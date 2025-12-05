# Proposal: Event-Driven Execution Strategy

## Overview

This proposal introduces a configurable execution strategy system that allows users to choose how Sequencer discovers and executes operations. The default behavior remains unchanged (explicit `sequencer:process` command), but users can opt into an event-driven strategy where operations execute automatically during `php artisan migrate`.

## Motivation

Currently, Sequencer requires users to run a separate command (`php artisan migrate && php artisan sequencer:process`) to execute both migrations and operations. While this provides explicit control, many teams prefer a single command that handles everything automatically.

Laravel's migration system dispatches events at key lifecycle points:

| Event | When Fired |
|-------|------------|
| `MigrationsStarted` | Before any migrations execute |
| `MigrationStarted` | Before each individual migration |
| `MigrationEnded` | After each individual migration |
| `MigrationsEnded` | After all migrations complete |
| `NoPendingMigrations` | When no migrations are pending |

By listening to these events, Sequencer can automatically execute operations at the correct points in the migration lifecycle.

## Proposed Solution

### Configuration

```php
// config/sequencer.php
return [
    // ...existing config...

    /*
    |--------------------------------------------------------------------------
    | Execution Strategy
    |--------------------------------------------------------------------------
    |
    | Determines how Sequencer discovers and executes operations.
    |
    | Supported strategies:
    | - "command"  : Operations only run via explicit sequencer:process command (default)
    | - "migration"    : Operations run automatically during php artisan migrate
    |
    */
    'strategy' => env('SEQUENCER_STRATEGY', 'command'),

    /*
    |--------------------------------------------------------------------------
    | Event Strategy Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration specific to the event-driven execution strategy.
    |
    */
    'migration_strategy' => [
        // Execute operations even when no migrations are pending
        'run_on_no_pending_migrations' => true,

        // Only execute operations in these migration commands
        // Prevents execution during migrate:fresh, migrate:refresh, etc.
        'allowed_commands' => [
            'migrate',
            'migrate:fresh', // Optional: include if you want ops after fresh
        ],

        // Execute synchronously even if operation implements Asynchronous
        // Useful for ensuring operations complete before migrate exits
        'force_sync' => false,
    ],
];
```

### Strategy Interface

```php
<?php declare(strict_types=1);

namespace Cline\Sequencer\Contracts;

/**
 * Defines how Sequencer discovers and executes operations.
 *
 * Strategies determine when operations are discovered, validated, and executed.
 * The default CommandStrategy requires explicit invocation via Artisan commands,
 * while MigrationStrategy hooks into Laravel's migration events for automatic execution.
 */
interface ExecutionStrategy
{
    /**
     * Register the strategy with Laravel.
     *
     * Called during service provider boot. Strategies should register any
     * event listeners, command modifications, or hooks needed for their
     * execution model.
     */
    public function register(): void;

    /**
     * Check if this strategy handles operation execution automatically.
     *
     * When true, the sequencer:process command will warn users that operations
     * are handled automatically and the command may be redundant.
     */
    public function isAutomatic(): bool;

    /**
     * Get the strategy identifier for logging and debugging.
     */
    public function name(): string;
}
```

### Command Strategy (Default)

```php
<?php declare(strict_types=1);

namespace Cline\Sequencer\Strategies;

use Cline\Sequencer\Contracts\ExecutionStrategy;

/**
 * Default strategy requiring explicit command invocation.
 *
 * Operations only execute when users run sequencer:process or sequencer:execute.
 * Provides maximum control and explicit behavior - nothing happens automatically.
 *
 * Typical workflow:
 *   php artisan migrate && php artisan sequencer:process
 *
 * Or combined in deployment scripts:
 *   php artisan migrate
 *   php artisan sequencer:process --isolate
 */
final readonly class CommandStrategy implements ExecutionStrategy
{
    public function register(): void
    {
        // No registration needed - commands are always available
    }

    public function isAutomatic(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'command';
    }
}
```

### Event Strategy

```php
<?php declare(strict_types=1);

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
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Event-driven strategy that hooks into Laravel's migration system.
 *
 * Operations execute automatically during php artisan migrate by listening
 * to Laravel's migration events. Operations are interleaved with migrations
 * based on timestamps, ensuring correct execution order.
 *
 * Typical workflow:
 *   php artisan migrate  # Operations run automatically!
 *
 * Event flow:
 *   MigrationsStarted → OperationsStarted
 *   MigrationEnded (for each) → Execute operations with timestamp ≤ migration
 *   MigrationsEnded → Execute remaining operations → OperationsEnded
 *   NoPendingMigrations → Execute all pending operations
 */
final class MigrationStrategy implements ExecutionStrategy
{
    /**
     * Track the last executed migration timestamp for interleaving.
     */
    private ?string $lastMigrationTimestamp = null;

    /**
     * Track executed operation timestamps to avoid duplicates.
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

    public function __construct(
        private readonly OperationDiscovery $discovery,
        private readonly Orchestrator $orchestrator,
    ) {}

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

    public function isAutomatic(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'event';
    }

    /**
     * Track when we enter a migrate command.
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
        }
    }

    /**
     * Track when we exit a migrate command.
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
        if (!$this->shouldProcess()) {
            return;
        }

        $this->log('info', 'Migration batch starting, preparing operation execution');

        Event::dispatch(new OperationsStarted(ExecutionMethod::Sync));
    }

    /**
     * Handle MigrationEnded event.
     *
     * Fired after each migration completes. We execute any operations
     * with timestamps less than or equal to this migration's timestamp.
     */
    private function onMigrationEnded(MigrationEnded $event): void
    {
        if (!$this->shouldProcess()) {
            return;
        }

        // Extract timestamp from migration name (YYYY_MM_DD_HHMMSS_name)
        $migrationName = $event->migration->getConnection() ?? '';
        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $event->migration::class, $matches)) {
            $timestamp = $matches[1];
        } else {
            // Try to get from the migration filename via reflection
            $timestamp = $this->extractMigrationTimestamp($event->migration);
        }

        if ($timestamp === null) {
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
        if (!$this->shouldProcess()) {
            return;
        }

        $this->log('info', 'Migration batch complete, executing remaining operations');

        // Execute all remaining operations (those with timestamps after last migration)
        $this->executeRemainingOperations();

        Event::dispatch(new OperationsEnded(ExecutionMethod::Sync));
    }

    /**
     * Handle NoPendingMigrations event.
     *
     * Fired when migrate is called but no migrations are pending.
     * We execute all pending operations if configured to do so.
     */
    private function onNoPendingMigrations(NoPendingMigrations $event): void
    {
        if (!$this->shouldProcess()) {
            return;
        }

        /** @var bool $runOnNoPending */
        $runOnNoPending = config('sequencer.migration_strategy.run_on_no_pending_migrations', true);

        if (!$runOnNoPending) {
            $this->log('info', 'No pending migrations and run_on_no_pending_migrations is false');
            Event::dispatch(new NoPendingOperations(ExecutionMethod::Sync));
            return;
        }

        $this->log('info', 'No pending migrations, executing all pending operations');

        $pending = $this->discovery->getPending();

        if ($pending === []) {
            Event::dispatch(new NoPendingOperations(ExecutionMethod::Sync));
            return;
        }

        Event::dispatch(new OperationsStarted(ExecutionMethod::Sync));

        $this->executeAllOperations();

        Event::dispatch(new OperationsEnded(ExecutionMethod::Sync));
    }

    /**
     * Check if we should process operations.
     */
    private function shouldProcess(): bool
    {
        return $this->inMigrateCommand;
    }

    /**
     * Execute operations with timestamps up to the given timestamp.
     */
    private function executeOperationsUpTo(string $timestamp): void
    {
        /** @var bool $forceSync */
        $forceSync = config('sequencer.migration_strategy.force_sync', false);

        $pending = $this->discovery->getPending();

        foreach ($pending as $operation) {
            $opTimestamp = $operation['timestamp'];

            // Skip if already executed
            if (isset($this->executedOperations[$operation['name']])) {
                continue;
            }

            // Only execute operations with timestamp <= migration timestamp
            if ($opTimestamp > $timestamp) {
                continue;
            }

            $this->log('info', "Executing operation {$operation['name']} (timestamp: {$opTimestamp})");

            // Execute via orchestrator process with single operation
            // This maintains all the existing behavior (transactions, events, etc.)
            $this->orchestrator->process(
                forceSync: $forceSync,
                from: $opTimestamp,
            );

            $this->executedOperations[$operation['name']] = true;
        }
    }

    /**
     * Execute all remaining operations after migrations complete.
     */
    private function executeRemainingOperations(): void
    {
        /** @var bool $forceSync */
        $forceSync = config('sequencer.migration_strategy.force_sync', false);

        $pending = $this->discovery->getPending();

        foreach ($pending as $operation) {
            // Skip if already executed during migration interleaving
            if (isset($this->executedOperations[$operation['name']])) {
                continue;
            }

            $this->log('info', "Executing remaining operation {$operation['name']}");

            $this->executedOperations[$operation['name']] = true;
        }

        // Execute all remaining at once
        if ($this->lastMigrationTimestamp !== null) {
            $this->orchestrator->process(
                forceSync: $forceSync,
                from: $this->lastMigrationTimestamp,
            );
        } else {
            $this->orchestrator->process(forceSync: $forceSync);
        }
    }

    /**
     * Execute all pending operations.
     */
    private function executeAllOperations(): void
    {
        /** @var bool $forceSync */
        $forceSync = config('sequencer.migration_strategy.force_sync', false);

        $this->orchestrator->process(forceSync: $forceSync);
    }

    /**
     * Extract timestamp from migration instance.
     */
    private function extractMigrationTimestamp(object $migration): ?string
    {
        $class = new \ReflectionClass($migration);
        $filename = $class->getFileName();

        if ($filename === false) {
            return null;
        }

        $basename = basename($filename, '.php');

        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $basename, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Log a message via configured channel.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        /** @var string $channel */
        $channel = config('sequencer.errors.log_channel', 'stack');

        Log::channel($channel)->{$level}("[Sequencer MigrationStrategy] {$message}", $context);
    }
}
```

### Service Provider Updates

```php
<?php declare(strict_types=1);

namespace Cline\Sequencer;

use Cline\Sequencer\Contracts\ExecutionStrategy;
use Cline\Sequencer\Strategies\CommandStrategy;
use Cline\Sequencer\Strategies\MigrationStrategy;
// ... existing imports ...

final class SequencerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ... existing registration ...

        $this->registerStrategy();
    }

    public function boot(): void
    {
        // ... existing boot ...

        $this->bootStrategy();
    }

    private function registerStrategy(): void
    {
        // Register both strategies as singletons
        $this->app->singleton(CommandStrategy::class);
        $this->app->singleton(MigrationStrategy::class);

        // Bind interface to configured strategy
        $this->app->singleton(ExecutionStrategy::class, function ($app) {
            /** @var string $strategy */
            $strategy = config('sequencer.strategy', 'command');

            return match ($strategy) {
                'event' => $app->make(MigrationStrategy::class),
                default => $app->make(CommandStrategy::class),
            };
        });
    }

    private function bootStrategy(): void
    {
        /** @var ExecutionStrategy $strategy */
        $strategy = $this->app->make(ExecutionStrategy::class);

        $strategy->register();
    }
}
```

## Behavior Matrix

### Command Strategy (Default)

| Action | Behavior |
|--------|----------|
| `php artisan migrate` | Only runs migrations |
| `php artisan sequencer:process` | Only runs operations |
| `php artisan migrate && sequencer:process` | Correct order for both |
| Async operations | Dispatched to queue |
| Rollback on failure | Full rollback of executed operations |
| Tags filtering | Supported via `--tags` |
| Isolation | Supported via `--isolate` |

### Event Strategy

| Action | Behavior |
|--------|----------|
| `php artisan migrate` | Runs migrations AND operations interleaved |
| `php artisan sequencer:process` | Works but warns about redundancy |
| Async operations | Dispatched unless `force_sync: true` |
| Rollback on failure | Operation-only rollback (migration already committed) |
| Tags filtering | Not supported (use command strategy) |
| Isolation | Uses Laravel's `--isolated` flag |

## Migration Path

### For New Projects

```php
// config/sequencer.php
'strategy' => 'event', // Opt into automatic execution
```

### For Existing Projects

No changes required. Default `command` strategy preserves existing behavior.

### Switching Strategies

```php
// Switch from command to event
'strategy' => 'event',

// Your deployment scripts change from:
php artisan migrate
php artisan sequencer:process

// To just:
php artisan migrate
```

## Edge Cases

### migrate:fresh

When using `migrate:fresh`, all tables are dropped before migrations run. The event strategy handles this by:

1. Only listening during allowed commands (configurable)
2. Default allows `migrate` but not `migrate:fresh`
3. Users can add `migrate:fresh` to allowed commands if operations are idempotent

### migrate:rollback

Migration rollbacks fire `MigrationEnded` with `$event->method === 'down'`. The event strategy should:

1. Check the method before executing operations
2. Only execute operations on `'up'` migrations
3. Optionally support operation rollback on migration rollback (future enhancement)

### Parallel Migrations

Laravel's `migrate` command with `--isolated` uses atomic locks. The event strategy respects this by:

1. Operations execute within the same lock context
2. No additional locking needed
3. `force_sync: true` ensures operations complete before lock releases

### Queue Workers

When using event strategy with async operations:

1. Operations are dispatched during `migrate`
2. Queue workers process them asynchronously
3. Use `force_sync: true` if operations must complete before deploy finishes

## Testing

### Testing Command Strategy

```php
it('does not execute operations during migrate with command strategy', function () {
    config(['sequencer.strategy' => 'command']);

    Artisan::call('migrate');

    expect(Operation::query()->count())->toBe(0);
});
```

### Testing Event Strategy

```php
it('executes operations during migrate with event strategy', function () {
    config(['sequencer.strategy' => 'event']);

    // Create a pending operation
    file_put_contents(
        database_path('operations/2024_01_01_000000_test_operation.php'),
        '<?php return new class implements Operation { public function handle(): void {} };'
    );

    Artisan::call('migrate');

    expect(Operation::query()->where('name', '2024_01_01_000000_test_operation')->exists())->toBeTrue();
});
```

## Future Enhancements

1. **Hybrid Strategy**: Execute critical operations via events, others via command
2. **Operation Rollback**: Support `migrate:rollback` triggering operation rollback
3. **Progress Output**: Integrate operation progress with migrate command output
4. **Dependency-Aware Interleaving**: Consider operation dependencies when interleaving

## Conclusion

The configurable strategy system provides flexibility while maintaining backward compatibility. Users who prefer explicit control keep the default command strategy. Teams wanting streamlined deployments can opt into the event strategy for automatic operation execution during migrations.

The event strategy hooks into Laravel's well-established migration event system, ensuring operations execute at the correct points without requiring modifications to Laravel's core migration commands.
