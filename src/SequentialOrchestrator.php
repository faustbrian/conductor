<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer;

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\ConditionalExecution;
use Cline\Sequencer\Contracts\EnvironmentSpecific;
use Cline\Sequencer\Contracts\HasTags;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Contracts\Rollbackable;
use Cline\Sequencer\Contracts\SpecifiesQueue;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Events\OperationFailed;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationSkipped;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Events\OperationStarted;
use Cline\Sequencer\Exceptions\CannotAcquireLockException;
use Cline\Sequencer\Exceptions\SkipOperationException;
use Cline\Sequencer\Exceptions\UnknownTaskTypeException;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Cline\Sequencer\Support\DependencyResolver;
use Cline\Sequencer\Support\MigrationDiscovery;
use Cline\Sequencer\Support\OperationDiscovery;
use Cline\Sequencer\Testing\OperationFake;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

use function array_any;
use function array_filter;
use function array_reverse;
use function array_values;
use function base_path;
use function config;
use function hrtime;
use function in_array;
use function is_array;
use function str_replace;
use function usort;

/**
 * Orchestrates sequential execution of migrations and operations with automatic rollback.
 *
 * Executes all migrations and operations one at a time in strict chronological order
 * based on timestamps. Operations execute synchronously in the current process unless
 * they implement Asynchronous, in which case they are dispatched to the queue. If any
 * operation fails, all previously executed operations implementing Rollbackable are
 * automatically rolled back in reverse order.
 *
 * Use this orchestrator as the default/safest option for most scenarios. It provides
 * predictable execution order, clear error handling, and automatic rollback. Best when
 * you don't need parallel execution or have operations that must run in strict sequence.
 *
 * ```php
 * // Operations execute one at a time in timestamp order
 * // 2024_01_01_000000_first_operation.php
 * final readonly class FirstOperation implements Operation
 * {
 *     public function handle(): void
 *     {
 *         // Runs first
 *     }
 * }
 *
 * // 2024_01_02_000000_second_operation.php
 * final readonly class SecondOperation implements Operation
 * {
 *     public function handle(): void
 *     {
 *         // Runs after FirstOperation completes
 *     }
 * }
 * ```
 *
 * @see BatchOrchestrator For parallel execution without rollback
 * @see DependencyGraphOrchestrator For dependency-aware wave execution
 * @see ScheduledOrchestrator For time-based delayed execution
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class SequentialOrchestrator implements Orchestrator
{
    /**
     * Create a new sequential orchestrator instance.
     *
     * @param OperationDiscovery $operationDiscovery Service for discovering pending operations
     *                                               from configured discovery paths
     * @param MigrationDiscovery $migrationDiscovery Service for discovering pending Laravel
     *                                               migrations from migration directories
     * @param DependencyResolver $dependencyResolver Service for resolving and validating
     *                                               operation dependencies to ensure correct
     *                                               execution order
     */
    public function __construct(
        private OperationDiscovery $operationDiscovery,
        private MigrationDiscovery $migrationDiscovery,
        private DependencyResolver $dependencyResolver,
    ) {}

    /**
     * Execute all pending migrations and operations in chronological order.
     *
     * @param bool        $isolate Whether to use atomic lock for multi-server safety
     * @param bool        $dryRun  Preview execution without actually running tasks
     * @param null|string $from    Resume from specific timestamp (YYYY_MM_DD_HHMMSS)
     * @param bool        $repeat  Re-execute already-completed operations
     *
     * @throws Throwable
     *
     * @return null|list<array{type: string, timestamp: string, name: string}>
     */
    public function process(bool $isolate = false, bool $dryRun = false, ?string $from = null, bool $repeat = false, bool $forceSync = false, bool $forceAsync = false, ?string $queue = null, mixed $tags = null): ?array
    {
        if ($dryRun) {
            return $this->preview($from, $repeat, $tags);
        }

        if ($isolate) {
            $this->processWithLock($from, $repeat, $forceSync, $forceAsync, $queue, $tags);

            return null;
        }

        $this->execute($from, $repeat, $forceSync, $forceAsync, $queue, $tags);

        return null;
    }

    /**
     * Preview pending tasks without executing them.
     *
     * @param  null|string                                                $from   Resume from specific timestamp
     * @param  bool                                                       $repeat Include already-executed operations
     * @return list<array{type: string, timestamp: string, name: string}>
     */
    private function preview(?string $from = null, bool $repeat = false, mixed $tags = null): array
    {
        $tasks = $this->discoverPendingTasks($repeat);

        if ($from) {
            $tasks = array_values(array_filter($tasks, fn (array $task): bool => $task['timestamp'] >= $from));
        }

        if ($tags !== null && $tags !== []) {
            $tasks = $this->filterTasksByTags($tasks, $tags);
        }

        $preview = [];

        foreach ($tasks as $task) {
            /** @var array{path?: string, name: string, class?: string} $data */
            $data = $task['data'];

            $name = match ($task['type']) {
                'migration' => $data['name'],
                'operation' => $data['name'],
                default => throw UnknownTaskTypeException::forType($task['type']),
            };

            $preview[] = [
                'type' => $task['type'],
                'timestamp' => $task['timestamp'],
                'name' => $name,
            ];
        }

        return $preview;
    }

    /**
     * Execute with atomic lock to prevent concurrent execution.
     *
     * @param null|string $from   Resume from specific timestamp
     * @param bool        $repeat Re-execute already-completed operations
     *
     * @throws Throwable
     */
    private function processWithLock(?string $from = null, bool $repeat = false, bool $forceSync = false, bool $forceAsync = false, ?string $queue = null, mixed $tags = null): void
    {
        /** @var string $lockStore */
        $lockStore = config('sequencer.execution.lock.store');

        /** @var int $timeout */
        $timeout = config('sequencer.execution.lock.timeout', 60);

        /** @var int $ttl */
        $ttl = config('sequencer.execution.lock.ttl', 600);

        // @phpstan-ignore-next-line Laravel's Cache facade provides lock() method via macro
        $lock = Cache::store($lockStore)->lock('sequencer:process', $ttl);

        // @phpstan-ignore-next-line Lock instance provides block() method
        if (!$lock->block($timeout)) {
            throw CannotAcquireLockException::timeoutExceeded();
        }

        try {
            $this->execute($from, $repeat, $forceSync, $forceAsync, $queue, $tags);
        } finally {
            // @phpstan-ignore-next-line Lock instance provides release() method
            $lock->release();
        }
    }

    /**
     * Discover and execute all pending tasks.
     *
     * @param null|string $from   Resume from specific timestamp
     * @param bool        $repeat Re-execute already-completed operations
     *
     * @throws Throwable
     */
    private function execute(?string $from = null, bool $repeat = false, bool $forceSync = false, bool $forceAsync = false, ?string $queue = null, mixed $tags = null): void
    {
        $tasks = $this->discoverPendingTasks($repeat);

        if ($from) {
            $tasks = array_values(array_filter($tasks, fn (array $task): bool => $task['timestamp'] >= $from));
        }

        if ($tags !== null && $tags !== []) {
            $tasks = $this->filterTasksByTags($tasks, $tags);
        }

        if ($tasks === []) {
            Event::dispatch(
                new NoPendingOperations(ExecutionMethod::Sync),
            );

            return;
        }

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::Sync),
        );

        $executedOperations = [];

        try {
            foreach ($tasks as $task) {
                match ($task['type']) {
                    'migration' => $this->executeMigration($task),
                    'operation' => $executedOperations[] = $this->executeOperation($task, $forceSync, $forceAsync, $queue),
                };
            }

            Event::dispatch(
                new OperationsEnded(ExecutionMethod::Sync),
            );
        } catch (Throwable $throwable) {
            // Rollback executed operations in reverse order
            $this->rollbackOperations(array_reverse($executedOperations));

            Event::dispatch(
                new OperationsEnded(ExecutionMethod::Sync),
            );

            throw $throwable;
        }
    }

    /**
     * Rollback operations that were executed before failure.
     *
     * @param list<array{operation: Operation, record: OperationModel}> $operations
     */
    private function rollbackOperations(array $operations): void
    {
        foreach ($operations as $item) {
            $operation = $item['operation'];
            $record = $item['record'];

            if (!$operation instanceof Rollbackable) {
                continue;
            }

            try {
                $operation->rollback();
                $record->update([
                    'rolled_back_at' => Date::now(),
                    'state' => OperationState::RolledBack,
                ]);

                /** @var string $logChannel */
                $logChannel = config('sequencer.errors.log_channel', 'stack');
                Log::channel($logChannel)
                    ->info('Operation rolled back successfully', [
                        'operation' => $record->name,
                    ]);
            } catch (Throwable $e) {
                /** @var string $logChannel */
                $logChannel = config('sequencer.errors.log_channel', 'stack');
                Log::channel($logChannel)
                    ->error('Failed to rollback operation', [
                        'operation' => $record->name,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
            }
        }
    }

    /**
     * Discover all pending migrations and operations, sorted by timestamp and dependencies.
     *
     * @param  bool                                                      $repeat Include already-executed operations for re-execution
     * @return list<array{type: string, timestamp: string, data: mixed}>
     */
    private function discoverPendingTasks(bool $repeat = false): array
    {
        $migrations = $this->migrationDiscovery->getPending();
        $operations = $this->operationDiscovery->getPending($repeat);

        $tasks = [];

        foreach ($migrations as $migration) {
            $tasks[] = [
                'type' => 'migration',
                'timestamp' => $migration['timestamp'],
                'data' => $migration,
            ];
        }

        foreach ($operations as $operation) {
            $tasks[] = [
                'type' => 'operation',
                'timestamp' => $operation['timestamp'],
                'data' => $operation,
            ];
        }

        // Sort by timestamp for initial chronological ordering
        usort($tasks, fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        // Re-sort respecting dependencies
        return $this->dependencyResolver->sortByDependencies($tasks);
    }

    /**
     * Execute a single migration.
     *
     * @param array{type: string, timestamp: string, data: mixed} $task
     *
     * @throws Throwable
     */
    private function executeMigration(array $task): void
    {
        /** @var array{path: string, name: string} $migration */
        $migration = $task['data'];

        // Convert absolute path to relative path from Laravel base
        $relativePath = str_replace(base_path().'/', '', $migration['path']);

        Artisan::call('migrate', [
            '--path' => $relativePath,
            '--force' => true,
        ]);
    }

    /**
     * Execute a single operation.
     *
     * @param array{type: string, timestamp: string, data: mixed} $task
     *
     * @throws Throwable
     *
     * @return array{operation: Operation, record: OperationModel}
     */
    private function executeOperation(array $task, bool $forceSync = false, bool $forceAsync = false, ?string $queue = null): array
    {
        /** @var array{class: string, name: string} $operationData */
        $operationData = $task['data'];
        $operationPath = $operationData['class']; // This is actually the file path now
        $operationName = $operationData['name'];

        /** @var Operation $operation */
        $operation = require $operationPath;

        // Record for testing fake if enabled
        OperationFake::record($operationName, $operation);

        // If faking, skip actual execution
        if (OperationFake::isFaking()) {
            $record = OperationModel::query()->make([
                'name' => $operationName,
                'type' => 'fake',
                'executed_at' => Date::now(),
                'completed_at' => Date::now(),
            ]);

            return ['operation' => $operation, 'record' => $record];
        }

        // Check if operation should run in current environment
        if ($operation instanceof EnvironmentSpecific && !App::environment($operation->environments())) {
            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)
                ->info('Operation skipped due to environment restriction', [
                    'operation' => $operationName,
                    'allowed_environments' => $operation->environments(),
                    'current_environment' => App::environment(),
                ]);

            // Create a record showing it was skipped
            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::Sync,
                'executed_at' => Date::now(),
                'completed_at' => Date::now(),
                'state' => OperationState::Completed,
            ]);

            return ['operation' => $operation, 'record' => $record];
        }

        // Check if operation should run
        if ($operation instanceof ConditionalExecution && !$operation->shouldRun()) {
            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)
                ->info('Operation skipped by shouldRun() condition', [
                    'operation' => $operationName,
                ]);

            // Create a record showing it was skipped
            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::Sync,
                'executed_at' => Date::now(),
                'completed_at' => Date::now(), // Mark as completed since it ran (decision was to skip)
                'state' => OperationState::Completed,
            ]);

            return ['operation' => $operation, 'record' => $record];
        }

        // Determine execution mode: force flags override operation interface
        $isAsync = $forceAsync || (!$forceSync && $operation instanceof Asynchronous);

        // Record operation start
        $record = OperationModel::query()->create([
            'name' => $operationName,
            'type' => $isAsync ? 'async' : ExecutionMethod::Sync,
            'executed_at' => Date::now(),
            'state' => OperationState::Pending,
        ]);

        if ($isAsync) {
            $this->dispatchAsync($operation, $record, $queue);

            return ['operation' => $operation, 'record' => $record];
        }

        $this->executeSynchronously($operation, $record);

        return ['operation' => $operation, 'record' => $record];
    }

    /**
     * Dispatch operation to queue for asynchronous execution.
     *
     * Pushes the operation to the configured queue connection and queue name
     * for background processing. The operation will be executed by a queue
     * worker outside the current request cycle.
     *
     * @param Operation      $operation The operation instance to execute asynchronously
     * @param OperationModel $record    The operation database record for tracking status
     */
    private function dispatchAsync(Operation $operation, OperationModel $record, ?string $queue = null): void
    {
        /** @var null|string $connection */
        $connection = config('sequencer.queue.connection');

        /** @var string $queueName */
        // Priority: CLI flag > Operation interface > Config
        $queueName = $queue
            ?? ($operation instanceof SpecifiesQueue ? $operation->queue() : null)
            ?? config('sequencer.queue.queue', 'default');

        /** @var int|string $recordId */
        $recordId = $record->id;

        Queue::connection($connection)->pushOn(
            $queueName,
            new ExecuteOperation($operation, $recordId),
        );
    }

    /**
     * Execute operation synchronously with optional transaction wrapping.
     *
     * Runs the operation immediately in the current process. Automatically wraps
     * execution in a database transaction if the operation implements WithinTransaction
     * or if auto-transaction is enabled in configuration. Updates the operation
     * record with completion or failure status, and records detailed error
     * information if execution fails.
     *
     * @param Operation      $operation The operation instance to execute synchronously
     * @param OperationModel $record    The operation database record for tracking status
     *
     * @throws Throwable Re-throws any exception that occurs during operation execution
     *                   after recording the error details to the database
     */
    private function executeSynchronously(Operation $operation, OperationModel $record): void
    {
        $startTime = hrtime(true);

        Event::dispatch(
            new OperationStarted($operation, ExecutionMethod::Sync),
        );

        $autoTransaction = config('sequencer.execution.auto_transaction', true);
        $useTransaction = $operation instanceof WithinTransaction || $autoTransaction;

        try {
            if ($useTransaction) {
                DB::transaction(fn () => $operation->handle());
            } else {
                $operation->handle();
            }

            $record->update([
                'completed_at' => Date::now(),
                'state' => OperationState::Completed,
            ]);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

            Event::dispatch(
                new OperationEnded($operation, ExecutionMethod::Sync, $elapsedMs),
            );
        } catch (SkipOperationException $exception) {
            $record->update([
                'skipped_at' => Date::now(),
                'skip_reason' => $exception->getMessage(),
                'state' => OperationState::Skipped,
            ]);

            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)->info('Operation skipped during execution', [
                'operation' => $record->name,
                'reason' => $exception->getMessage(),
            ]);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

            Event::dispatch(
                new OperationSkipped($operation, ExecutionMethod::Sync, $exception->getMessage(), $elapsedMs),
            );
        } catch (Throwable $throwable) {
            $record->update([
                'failed_at' => Date::now(),
                'state' => OperationState::Failed,
            ]);

            $this->recordError($record, $throwable);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

            Event::dispatch(
                new OperationFailed($operation, ExecutionMethod::Sync, $throwable, $elapsedMs),
            );

            throw $throwable;
        }
    }

    /**
     * Record operation error for audit trail and logging.
     *
     * Creates a detailed error record in the operation_errors table containing
     * exception details, stack trace, and contextual information. Also logs the
     * error to the configured log channel for immediate alerting and monitoring.
     *
     * @param OperationModel $record    The operation database record that failed
     * @param Throwable      $exception The exception that caused the operation to fail
     */
    private function recordError(OperationModel $record, Throwable $exception): void
    {
        if (!config('sequencer.errors.record', true)) {
            return;
        }

        OperationError::query()->create([
            'operation_id' => $record->id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'context' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'code' => $exception->getCode(),
            ],
            'created_at' => Date::now(),
        ]);

        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');
        Log::channel($logChannel)->error('Operation failed', [
            'operation' => $record->name,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Filter tasks to only those with operations that match the specified tags.
     *
     * Migrations are always included regardless of tags. Only operations that
     * implement HasTags and have at least one matching tag are included.
     *
     * @param  list<array{type: string, timestamp: string, data: mixed}> $tasks All discovered tasks
     * @param  mixed                                                     $tags  Tags to filter by (array of strings)
     * @return list<array{type: string, timestamp: string, data: mixed}> Filtered tasks
     */
    private function filterTasksByTags(array $tasks, mixed $tags): array
    {
        if (!is_array($tags) || $tags === []) {
            return $tasks;
        }

        return array_values(array_filter($tasks, function (array $task) use ($tags): bool {
            // Always include migrations
            if ($task['type'] === 'migration') {
                return true;
            }

            // For operations, check if they have matching tags
            if ($task['type'] === 'operation') {
                /** @var array{class: string, name: string} $operationData */
                $operationData = $task['data'];
                $operationPath = $operationData['class'];

                /** @var Operation $operation */
                $operation = require $operationPath;

                // Only include operations that implement HasTags
                if (!$operation instanceof HasTags) {
                    return false;
                }

                $operationTags = $operation->tags();

                return array_any($tags, fn ($tag): bool => in_array($tag, $operationTags, true));
            }

            return false;
        }));
    }
}
