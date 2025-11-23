[![GitHub Workflow Status][ico-tests]][link-tests]
[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

------

A powerful Laravel package that orchestrates sequential execution of migrations and operations. Ensures database changes and business logic run in chronological order during deployments, preventing conflicts and maintaining data integrity.

## Requirements

> **Requires [PHP 8.4+](https://php.net/releases/)** and Laravel 11+

## Installation

```bash
composer require cline/sequencer
```

## Quick Start

```php
// Create an operation
php artisan make:operation SeedInitialData

// In database/operations/2024_01_15_120000_seed_initial_data.php
use Cline\Sequencer\Contracts\Operation;

class SeedInitialData implements Operation
{
    public function handle(): void
    {
        // Your business logic here
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);
    }
}

// Execute migrations and operations sequentially
php artisan sequencer:process
```

## The Problem Sequencer Solves

Traditional Laravel deployments separate migrations and operations:

```bash
php artisan migrate           # Runs ALL migrations first
php artisan operation:process # Then runs ALL operations
```

This causes issues when migrations and operations are interdependent:

```
Migration 001: Create users table
Migration 002: Add status column to users
Operation 001: Seed admin user  ← FAILS! Status column doesn't exist yet
```

Sequencer executes them **sequentially by timestamp**:

```
2024_01_01_000000_create_users_table (migration)
2024_01_02_000000_seed_admin_user (operation)
2024_01_03_000000_add_status_column (migration)
2024_01_04_000000_update_user_statuses (operation)
```

## Documentation

### Core Concepts
- **[Getting Started](cookbook/getting-started.md)** - Installation, configuration, and first operation
- **[Basic Usage](cookbook/basic-usage.md)** - Core operations and sequential execution
- **[Monitoring & Status](cookbook/monitoring-status.md)** - Check pending, completed, and failed operations

### Advanced Features
- **[Orchestration Strategies](cookbook/orchestration-strategies.md)** - Sequential, batch, transactional, dependency-based, and scheduled execution
- **[Advanced Operations](cookbook/advanced-operations.md)** - Retries, timeouts, batching, chaining, middleware, unique operations, encryption, tags, lifecycle hooks
- **[Programmatic Usage](cookbook/programmatic-usage.md)** - Facade API, conditional execution, status checks, error handling
- **[Events](cookbook/events.md)** - Operation lifecycle events for logging, monitoring, and custom workflows
- **[Rollback Support](cookbook/rollback-support.md)** - Automatic rollback on failures
- **[Dependencies](cookbook/dependencies.md)** - Explicit operation ordering
- **[Conditional Execution](cookbook/conditional-execution.md)** - Runtime execution conditions
- **[Skip Operations](cookbook/skip-operations.md)** - Skip operations at runtime with SkipOperationException
- **[Advanced Usage](cookbook/advanced-usage.md)** - Transactions, async operations, observability

## Key Features

### Orchestration Strategies
- ✅ **Sequential Orchestrator** - Default chronological execution by timestamp
- ✅ **Batch Orchestrator** - Parallel execution of independent operations
- ✅ **Transactional Batch** - All-or-nothing execution with automatic rollback on failure
- ✅ **AllowedToFail Batch** - Continue execution even when non-critical operations fail
- ✅ **Dependency Graph** - DAG-based wave execution with parallel operations per wave
- ✅ **Scheduled Orchestrator** - Time-based delayed execution for maintenance windows

### Execution Control
- ✅ **Flexible Orchestration** - Switch strategies via config or fluent API (`Sequencer::using(...)`)
- ✅ **Dependency Resolution** - Explicit operation dependencies with topological sorting
- ✅ **Conditional Execution** - Skip operations based on runtime conditions or throw `SkipOperationException`
- ✅ **Dry-Run Mode** - Preview execution order without running anything (`--dry-run`)
- ✅ **Partial Execution** - Resume from specific timestamp after failures (`--from`)
- ✅ **Atomic Locking** - Prevent concurrent execution in multi-server environments (`--isolate`)

### Queue & Async
- ✅ **Asynchronous Operations** - Queue operations for background processing
- ✅ **Retry Mechanisms** - Automatic retries with configurable backoff strategies
- ✅ **Timeouts** - Set execution time limits with configurable failure behavior
- ✅ **Batching** - Execute multiple operations together with shared tracking
- ✅ **Chaining** - Sequential operation execution with automatic rollback
- ✅ **Middleware** - Apply Laravel queue middleware to operations
- ✅ **Unique Operations** - Prevent duplicate execution with unique locks

### Security & Reliability
- ✅ **Encryption** - Automatic payload encryption for sensitive operations
- ✅ **Auto-Transaction** - Configurable database transaction wrapping
- ✅ **Rollback Support** - Automatic rollback of executed operations when failures occur
- ✅ **Exception Handling** - Control failure behavior with `maxExceptions`
- ✅ **Lifecycle Hooks** - Before/after/failed callbacks for operation execution
- ✅ **Idempotency** - Mark operations safe for multiple executions

### Monitoring & Events
- ✅ **Operation Events** - Lifecycle events (OperationStarted, OperationEnded, etc.) for logging and monitoring
- ✅ **Operation Tags** - Tag operations for filtering and monitoring
- ✅ **Pulse/Telescope Integration** - Real-time monitoring and debugging
- ✅ **Error Tracking** - Detailed error recording with stack traces and context

### Developer Experience
- ✅ **Programmatic API** - Full facade API for conditional execution and status checks
- ✅ **Testing Helpers** - `OperationFake::assertDispatched()` for comprehensive testing
- ✅ **Flexible Configuration** - Support for ID/ULID/UUID primary keys and morph types

## Command Line Interface

```bash
# Execute all pending migrations and operations
php artisan sequencer:process

# Preview execution order without running
php artisan sequencer:process --dry-run

# Prevent concurrent execution (multi-server)
php artisan sequencer:process --isolate

# Resume from specific timestamp
php artisan sequencer:process --from=2024_01_15_120000

# Check status of migrations and operations
php artisan sequencer:status

# View only pending items
php artisan sequencer:status --pending

# View only failed operations with errors
php artisan sequencer:status --failed -v

# Process scheduled operations (run via Laravel scheduler every minute)
php artisan sequencer:scheduled
```

## Orchestration Strategies

Choose the right orchestrator for your use case:

```php
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\Orchestrators\{
    SequentialOrchestrator,      // Default: chronological order
    BatchOrchestrator,            // Parallel execution
    TransactionalBatchOrchestrator, // All-or-nothing with rollback
    AllowedToFailBatchOrchestrator, // Continue on non-critical failures
    DependencyGraphOrchestrator,  // DAG-based waves
    ScheduledOrchestrator,        // Time-based execution
};

// Configure globally
// config/sequencer.php: 'orchestrator' => BatchOrchestrator::class

// Or use fluent API for specific executions
Sequencer::using(TransactionalBatchOrchestrator::class)->executeAll();
Sequencer::using(DependencyGraphOrchestrator::class)->preview();
```

See **[Orchestration Strategies](cookbook/orchestration-strategies.md)** for detailed examples.


## Operation Interfaces

Sequencer provides rich interfaces to customize operation behavior:

```php
use Cline\Sequencer\Contracts\{
    Operation,
    Asynchronous,
    Rollbackable,
    Retryable,
    Timeoutable,
    HasMiddleware,
    HasTags,
    HasMaxExceptions,
    HasLifecycleHooks,
    ShouldBeEncrypted,
    ShouldBeUnique,
    WithinTransaction,
    ConditionalExecution,
    HasDependencies,
    Idempotent,
    AllowedToFail,
    Scheduled,
};

// Basic operation
class SeedData implements Operation
{
    public function handle(): void { }
}

// Async operation with retries and lifecycle hooks
class ImportData implements Operation, Asynchronous, Retryable, HasLifecycleHooks
{
    public function handle(): void { }

    public function tries(): int { return 5; }
    public function backoff(): array { return [1, 5, 10]; }

    public function before(): void { Log::info('Starting import'); }
    public function after(): void { Log::info('Import completed'); }
    public function failed(\Throwable $e): void { Log::error('Import failed', ['error' => $e]); }
}

// Secure operation with encryption and tags
class ProcessPayments implements Operation, ShouldBeEncrypted, HasTags
{
    public function handle(): void { }

    public function tags(): array { return ['payments', 'sensitive']; }
}

// Conditional operation with dependencies
class SeedProductionData implements Operation, ConditionalExecution, HasDependencies
{
    public function handle(): void { }

    public function shouldRun(): bool { return app()->environment('production'); }
    public function dependsOn(): array { return [CreateUsersTable::class]; }
}

// Scheduled operation with rollback support
class ScheduledMaintenance implements Operation, Scheduled, Rollbackable
{
    public function executeAt(): \DateTimeInterface { return now()->setTime(2, 0); }
    public function handle(): void { }
    public function rollback(): void { }
}

// Non-critical operation allowed to fail
class SendWelcomeEmails implements Operation, AllowedToFail
{
    public function handle(): void { }
}

// Rollbackable operation with transaction control
class MigrateUserData implements Operation, Rollbackable, WithinTransaction
{
    public function handle(): void { }
    public function rollback(): void { }
}
```

## Configuration

Sequencer is highly configurable with support for multiple orchestration strategies:

```php
// config/sequencer.php
return [
    // Orchestration strategy (can override at runtime)
    'orchestrator' => env('SEQUENCER_ORCHESTRATOR', SequentialOrchestrator::class),

    // Primary key type: 'id', 'ulid', or 'uuid'
    'primary_key_type' => env('SEQUENCER_PRIMARY_KEY_TYPE', 'id'),

    // Polymorphic relationship type
    'morph_type' => env('SEQUENCER_MORPH_TYPE', 'morph'),

    'execution' => [
        // Auto-wrap operations in transactions
        'auto_transaction' => env('SEQUENCER_AUTO_TRANSACTION', true),

        // Multiple operation discovery paths
        'discovery_paths' => [
            database_path('operations'),
        ],

        // Atomic lock for multi-server deployments
        'lock' => [
            'store' => env('SEQUENCER_LOCK_STORE', 'redis'),
            'timeout' => env('SEQUENCER_LOCK_TIMEOUT', 60),
            'ttl' => env('SEQUENCER_LOCK_TTL', 600),
        ],
    ],

    'reporting' => [
        'pulse' => env('SEQUENCER_PULSE_ENABLED', false),
        'telescope' => env('SEQUENCER_TELESCOPE_ENABLED', false),
    ],
];
```

## Testing

Sequencer includes comprehensive testing helpers:

```php
use Cline\Sequencer\Testing\OperationFake;

test('operation is dispatched', function () {
    OperationFake::setup();

    // Your code that triggers operations

    OperationFake::assertDispatched(SeedInitialData::class);
    OperationFake::assertDispatchedTimes(SeedInitialData::class, 1);
    OperationFake::assertNotDispatched(OtherOperation::class);
});
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please use the [GitHub security reporting form][link-security] rather than the issue queue.

## Credits

- [Brian Faust][link-maintainer]
- [All Contributors][link-contributors]

## License

The MIT License. Please see [License File](LICENSE.md) for more information.

[ico-tests]: https://github.com/faustbrian/sequencer/actions/workflows/quality-assurance.yaml/badge.svg
[ico-version]: https://img.shields.io/packagist/v/cline/sequencer.svg
[ico-license]: https://img.shields.io/badge/License-MIT-green.svg
[ico-downloads]: https://img.shields.io/packagist/dt/cline/sequencer.svg

[link-tests]: https://github.com/faustbrian/sequencer/actions
[link-packagist]: https://packagist.org/packages/cline/sequencer
[link-downloads]: https://packagist.org/packages/cline/sequencer
[link-security]: https://github.com/faustbrian/sequencer/security
[link-maintainer]: https://github.com/faustbrian
[link-contributors]: ../../contributors
