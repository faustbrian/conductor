<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Strategies;

use Cline\Sequencer\Contracts\ExecutionStrategy;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Support\OperationDiscovery;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Throwable;

use function array_key_exists;
use function basename;
use function config;
use function in_array;
use function preg_match;
use function sprintf;

/**
 * Event-driven strategy that hooks into Laravel's migration system.
 *
 * Operations execute automatically during `php artisan migrate` by listening
 * to Laravel's migration events. Operations are interleaved with migrations
 * based on timestamps, ensuring correct execution order.
 *
 * Typical workflow:
 * ```bash
 * php artisan migrate  # Operations run automatically!
 * ```
 *
 * Event flow:
 * - MigrationsStarted → OperationsStarted
 * - MigrationEnded (for each) → Execute operations with timestamp ≤ migration
 * - MigrationsEnded → Execute remaining operations → OperationsEnded
 * - NoPendingMigrations → Execute all pending operations
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MigrationStrategy implements ExecutionStrategy
{
    /**
     * Track the last executed migration timestamp for interleaving.
     */
    private ?string $lastMigrationTimestamp = null;

    /**
     * Track executed operation names to avoid duplicates.
     *
     * @var array<string, bool>
     */
    private array $executedOperations = [];

    /**
     * Track if we're inside an allowed migrate command.
     */
    private bool $inMigrateCommand = false;

    /**
     * Track the current command name.
     */
    private ?string $currentCommand = null;

    /**
     * Track if batch events have been fired.
     */
    private bool $batchStarted = false;

    /**
     * Create a new event strategy instance.
     *
     * @param OperationDiscovery $discovery    Service for discovering pending operations
     * @param Orchestrator       $orchestrator Default orchestrator for operation execution
     */
    public function __construct(
        private readonly OperationDiscovery $discovery,
        private readonly Orchestrator $orchestrator,
    ) {}

    /**
     * Register the strategy with Laravel.
     *
     * Sets up event listeners for Laravel's migration events and console
     * command events to track execution context and trigger operations.
     */
    public function register(): void
    {
        // Track command context
        Event::listen(CommandStarting::class, $this->onCommandStarting(...));
        Event::listen(CommandFinished::class, $this->onCommandFinished(...));

        // Hook into migration lifecycle
        Event::listen(MigrationsStarted::class, $this->onMigrationsStarted(...));
        Event::listen(MigrationEnded::class, $this->onMigrationEnded(...));
        Event::listen(MigrationsEnded::class, $this->onMigrationsEnded(...));
        Event::listen(NoPendingMigrations::class, $this->onNoPendingMigrations(...));
    }

    /**
     * Check if this strategy handles operation execution automatically.
     *
     * Event strategy automatically executes operations during migrations.
     *
     * @return bool Always true - operations execute automatically
     */
    public function isAutomatic(): bool
    {
        return true;
    }

    /**
     * Get the strategy identifier for logging and debugging.
     *
     * @return string Returns 'migration'
     */
    public function name(): string
    {
        return 'migration';
    }

    /**
     * Track when we enter a migrate command.
     *
     * Resets state and enables operation execution only for allowed commands.
     */
    private function onCommandStarting(CommandStarting $event): void
    {
        $this->currentCommand = $event->command;

        /** @var array<string> $allowedCommands */
        $allowedCommands = config('sequencer.migration_strategy.allowed_commands', ['migrate']);

        if (in_array($event->command, $allowedCommands, true)) {
            $this->inMigrateCommand = true;
            $this->lastMigrationTimestamp = null;
            $this->executedOperations = [];
            $this->batchStarted = false;
        }
    }

    /**
     * Track when we exit a migrate command.
     *
     * Cleans up state when the command finishes.
     */
    private function onCommandFinished(CommandFinished $event): void
    {
        if ($event->command === $this->currentCommand) {
            $this->inMigrateCommand = false;
            $this->currentCommand = null;
        }
    }

    /**
     * Handle MigrationsStarted event.
     *
     * Fired before any migrations execute. We dispatch OperationsStarted
     * to signal that operation processing is beginning.
     */
    private function onMigrationsStarted(MigrationsStarted $event): void
    {
        if (!$this->inMigrateCommand) {
            return;
        }

        $this->log('info', 'Migration batch starting, preparing operation execution');

        $this->batchStarted = true;

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::Sync),
        );
    }

    /**
     * Handle MigrationEnded event.
     *
     * Fired after each migration completes. We execute any operations
     * with timestamps less than or equal to this migration's timestamp.
     * Only processes 'up' migrations, not rollbacks.
     */
    private function onMigrationEnded(MigrationEnded $event): void
    {
        if (!$this->inMigrateCommand) {
            return;
        }

        // Only execute operations on 'up' migrations, not rollbacks
        if ($event->method !== 'up') {
            return;
        }

        $timestamp = $this->extractMigrationTimestamp($event->migration);

        if ($timestamp === null) {
            $this->log('warning', 'Could not extract timestamp from migration', [
                'migration' => $event->migration::class,
            ]);

            return;
        }

        $this->lastMigrationTimestamp = $timestamp;
        $this->executeOperationsUpTo($timestamp);
    }

    /**
     * Handle MigrationsEnded event.
     *
     * Fired after all migrations complete. We execute any remaining
     * operations that haven't been executed yet.
     */
    private function onMigrationsEnded(MigrationsEnded $event): void
    {
        if (!$this->inMigrateCommand) {
            return;
        }

        $this->log('info', 'Migration batch complete, executing remaining operations');

        // Execute all remaining operations (those with timestamps after last migration)
        $this->executeRemainingOperations();

        if ($this->batchStarted) {
            Event::dispatch(
                new OperationsEnded(ExecutionMethod::Sync),
            );
            $this->batchStarted = false;
        }
    }

    /**
     * Handle NoPendingMigrations event.
     *
     * Fired when migrate is called but no migrations are pending.
     * We execute all pending operations if configured to do so.
     */
    private function onNoPendingMigrations(NoPendingMigrations $event): void
    {
        if (!$this->inMigrateCommand) {
            return;
        }

        /** @var bool $runOnNoPending */
        $runOnNoPending = config('sequencer.migration_strategy.run_on_no_pending_migrations', true);

        if (!$runOnNoPending) {
            $this->log('info', 'No pending migrations and run_on_no_pending_migrations is false');
            Event::dispatch(
                new NoPendingOperations(ExecutionMethod::Sync),
            );

            return;
        }

        $this->log('info', 'No pending migrations, executing all pending operations');

        $pending = $this->discovery->getPending();

        if ($pending === []) {
            Event::dispatch(
                new NoPendingOperations(ExecutionMethod::Sync),
            );

            return;
        }

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::Sync),
        );

        $this->executeAllOperations();

        Event::dispatch(
            new OperationsEnded(ExecutionMethod::Sync),
        );
    }

    /**
     * Execute operations with timestamps up to the given timestamp.
     *
     * Discovers pending operations and executes those with timestamps
     * less than or equal to the provided migration timestamp.
     *
     * @param string $timestamp Migration timestamp (YYYY_MM_DD_HHMMSS format)
     */
    private function executeOperationsUpTo(string $timestamp): void
    {
        /** @var bool $forceSync */
        $forceSync = config('sequencer.migration_strategy.force_sync', false);

        $pending = $this->discovery->getPending();

        foreach ($pending as $operation) {
            $opTimestamp = $operation['timestamp'];
            $opName = $operation['name'];

            // Skip if already executed
            if (array_key_exists($opName, $this->executedOperations)) {
                continue;
            }

            // Only execute operations with timestamp <= migration timestamp
            if ($opTimestamp > $timestamp) {
                continue;
            }

            $this->log('info', sprintf('Executing operation %s (timestamp: %s)', $opName, $opTimestamp));

            try {
                // Execute via orchestrator for single operation
                // Use from parameter to target specific timestamp range
                $this->orchestrator->process(
                    from: $opTimestamp,
                    forceSync: $forceSync,
                );

                $this->executedOperations[$opName] = true;
            } catch (Throwable $e) {
                $this->log('error', 'Failed to execute operation '.$opName, [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Execute all remaining operations after migrations complete.
     *
     * Executes any operations that weren't executed during migration
     * interleaving (those with timestamps after the last migration).
     */
    private function executeRemainingOperations(): void
    {
        /** @var bool $forceSync */
        $forceSync = config('sequencer.migration_strategy.force_sync', false);

        $pending = $this->discovery->getPending();
        $hasRemaining = false;

        foreach ($pending as $operation) {
            // Skip if already executed during migration interleaving
            if (array_key_exists($operation['name'], $this->executedOperations)) {
                continue;
            }

            $hasRemaining = true;
            $this->log('info', 'Executing remaining operation '.$operation['name']);
            $this->executedOperations[$operation['name']] = true;
        }

        if (!$hasRemaining) {
            return;
        }

        // Execute all remaining at once via orchestrator
        try {
            if ($this->lastMigrationTimestamp !== null) {
                $this->orchestrator->process(
                    from: $this->lastMigrationTimestamp,
                    forceSync: $forceSync,
                );
            } else {
                $this->orchestrator->process(forceSync: $forceSync);
            }
        } catch (Throwable $throwable) {
            $this->log('error', 'Failed to execute remaining operations', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    /**
     * Execute all pending operations.
     *
     * Used when no migrations are pending but operations exist.
     */
    private function executeAllOperations(): void
    {
        /** @var bool $forceSync */
        $forceSync = config('sequencer.migration_strategy.force_sync', false);

        try {
            $this->orchestrator->process(forceSync: $forceSync);
        } catch (Throwable $throwable) {
            $this->log('error', 'Failed to execute all operations', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    /**
     * Extract timestamp from migration instance.
     *
     * Attempts to extract the YYYY_MM_DD_HHMMSS timestamp from the migration's
     * filename via reflection.
     *
     * @param  Migration   $migration The migration instance
     * @return null|string Timestamp in YYYY_MM_DD_HHMMSS format, or null if extraction fails
     */
    private function extractMigrationTimestamp(Migration $migration): ?string
    {
        try {
            $class = new ReflectionClass($migration);
            $filename = $class->getFileName();

            if ($filename === false) {
                return null;
            }

            $basename = basename($filename, '.php');

            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $basename, $matches)) {
                return $matches[1];
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Log a message via configured channel.
     *
     * @param string               $level   Log level (info, warning, error)
     * @param string               $message Log message
     * @param array<string, mixed> $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        /** @var string $channel */
        $channel = config('sequencer.errors.log_channel', 'stack');

        Log::channel($channel)->{$level}('[Sequencer MigrationStrategy] '.$message, $context);
    }
}
