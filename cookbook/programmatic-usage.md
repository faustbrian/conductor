# Programmatic Usage

This guide covers using Sequencer programmatically without the Artisan CLI. This is useful for custom cronjobs, scheduled tasks, or when you need fine-grained control over operation execution.

## Quick Start with Facade

The `Sequencer` facade provides the simplest API for programmatic usage:

```php
use Cline\Sequencer\Facades\Sequencer;

// Execute all pending operations
Sequencer::executeAll();

// Execute specific operation by class name
Sequencer::execute(SeedAdminUser::class);

// Execute specific operation by file name
Sequencer::execute('2024_01_15_120000_seed_admin_user');

// Queue operation for background execution
Sequencer::execute('2024_01_15_120000_process_large_dataset', async: true);

// Execute synchronously (bypass queue)
Sequencer::executeSync(ProcessUrgentTask::class);

// Conditional execution
Sequencer::executeIf($user->isPremium(), ProcessPremiumFeatures::class);
Sequencer::executeUnless($alreadyProcessed, ProcessData::class);

// Chain operations (sequential)
Sequencer::chain([
    ProcessPodcast::class,
    OptimizePodcast::class,
    ReleasePodcast::class,
])->dispatch();

// Batch operations (parallel)
Sequencer::batch([
    ImportUsers::class,
    ImportProducts::class,
    ImportOrders::class,
])->dispatch();

// Preview pending operations
$preview = Sequencer::preview();

// Check if operation has executed
if (Sequencer::hasExecuted(SeedAdminUser::class)) {
    // Already ran
}

// Rollback an operation
Sequencer::rollback(SeedAdminUser::class);
```

## Executing All Operations

### Using the Facade

```php
use Cline\Sequencer\Facades\Sequencer;

// Execute all pending operations
Sequencer::executeAll();

// Execute with atomic lock (multi-server safety)
Sequencer::executeAll(isolate: true);

// Resume from specific timestamp
Sequencer::executeAll(from: '2024_01_15_120000');

// Re-execute already-completed operations
Sequencer::executeAll(repeat: true);

// Combine options
Sequencer::executeAll(
    isolate: true,
    from: '2024_01_15_120000',
    repeat: false
);
```

### Using the Orchestrator

For advanced use cases, you can use the `SequentialOrchestrator` directly:

```php
use Cline\Sequencer\SequentialOrchestrator;

// Get orchestrator from container
$orchestrator = app(SequentialOrchestrator::class);

// Execute all pending operations
$orchestrator->process();

// With options
$orchestrator->process(isolate: true);

// Preview what will execute (dry run)
$preview = $orchestrator->process(dryRun: true);
foreach ($preview as $task) {
    echo "{$task['type']}: {$task['name']}\n";
}
```

### In Scheduled Tasks

Add to your `app/Console/Kernel.php`:

```php
use Cline\Sequencer\Facades\Sequencer;

protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        Sequencer::executeAll(isolate: true);
    })->hourly();
}
```

### In Custom Cronjobs

Create a custom command:

```php
namespace App\Console\Commands;

use Cline\Sequencer\Facades\Sequencer;
use Illuminate\Console\Command;

class ProcessSequencerOperations extends Command
{
    protected $signature = 'app:sequencer';

    public function handle(): int
    {
        try {
            Sequencer::executeAll(isolate: true);
            $this->info('Operations processed successfully');
            return 0;
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            return 1;
        }
    }
}
```

Then in your crontab:

```bash
0 * * * * cd /var/www/app && php artisan app:sequencer
```

## Executing Individual Operations

### Using the Facade

Execute specific operations by class name or file name:

```php
use Cline\Sequencer\Facades\Sequencer;

// By class name
Sequencer::execute(SeedAdminUser::class);

// By file name (with or without .php extension)
Sequencer::execute('2024_01_15_120000_seed_admin_user');
Sequencer::execute('2024_01_15_120000_seed_admin_user.php');

// By full path
Sequencer::execute('/path/to/operations/2024_01_15_120000_seed_admin_user.php');

// Execute asynchronously (queued)
Sequencer::execute(ProcessLargeDataset::class, async: true);

// Execute without database tracking
Sequencer::execute(SeedAdminUser::class, record: false);
```

### Direct Execution (Low-Level)

For cases where you need direct control without the facade:

```php
use Cline\Sequencer\Contracts\Operation;

// Load operation from file
$operation = require database_path('operations/2024_01_15_120000_seed_admin_user.php');

// Execute directly
$operation->handle();
```

### With Transaction Support

```php
use Illuminate\Support\Facades\DB;

$operation = require database_path('operations/2024_01_15_120000_seed_admin_user.php');

DB::transaction(function () use ($operation) {
    $operation->handle();
});
```

## Dispatching Async Operations

### Using the Facade

Queue operations for background execution:

```php
use Cline\Sequencer\Facades\Sequencer;

// Queue specific operation
Sequencer::execute(ProcessLargeDataset::class, async: true);

// Queue operation by file name
Sequencer::execute('2024_01_15_120000_process_large_dataset', async: true);
```

The operation will be dispatched to the configured queue connection and queue name (see `config/sequencer.php`).

### Low-Level Queue Dispatch

For advanced queue control:

```php
use Cline\Sequencer\Jobs\ExecuteOperation;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Illuminate\Support\Facades\Date;

$operation = require database_path('operations/2024_01_15_120000_process_large_dataset.php');

// Create operation record
$record = OperationModel::create([
    'name' => '2024_01_15_120000_process_large_dataset',
    'type' => 'async',
    'executed_at' => Date::now(),
]);

// Dispatch to queue
ExecuteOperation::dispatch($operation, $record->id)
    ->onQueue('operations');
```

## Checking Operation Status

### Using the Facade

```php
use Cline\Sequencer\Facades\Sequencer;

// Check if operation has executed
if (Sequencer::hasExecuted(SeedAdminUser::class)) {
    // Already executed
}

// Check if operation failed
if (Sequencer::hasFailed(SeedAdminUser::class)) {
    // Failed during execution
}

// Get error details
$errors = Sequencer::getErrors(SeedAdminUser::class);
foreach ($errors as $error) {
    echo $error->exception; // Exception class
    echo $error->message;   // Error message
    echo $error->trace;     // Stack trace
}
```

### Using Models

The `Operation` model provides expressive query scopes for filtering operations:

```php
use Cline\Sequencer\Database\Models\Operation;

// Status scopes
$completed = Operation::completed()->get();
$failed = Operation::failed()->get();
$pending = Operation::pending()->get();
$rolledBack = Operation::rolledBack()->get();
$successful = Operation::successful()->get(); // Completed and not rolled back

// Type scopes
$sync = Operation::synchronous()->get();
$async = Operation::asynchronous()->get();

// Name filtering
$operation = Operation::named(SeedAdminUser::class)->first();
$seedOps = Operation::named('seed_%')->get(); // Wildcard search

// Date scopes
$today = Operation::today()->get();
$range = Operation::between('2024-01-01', '2024-01-31')->get();

// Error scopes
$withErrors = Operation::withErrors()->get();
$withoutErrors = Operation::withoutErrors()->get();

// Ordering
$latest = Operation::latest()->get(); // Most recent first
$oldest = Operation::oldest()->get(); // Oldest first

// Combine scopes
$recentFailures = Operation::failed()
    ->today()
    ->latest()
    ->get();

// Check if specific operation ran
$executed = Operation::named(SeedAdminUser::class)
    ->completed()
    ->exists();
```

## Error Handling

### Using the Facade

Errors are automatically captured when using the facade:

```php
use Cline\Sequencer\Facades\Sequencer;
use Illuminate\Support\Facades\Log;

try {
    Sequencer::execute(SeedAdminUser::class);
} catch (\Throwable $e) {
    Log::error('Operation failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ]);

    // Check recorded errors
    $errors = Sequencer::getErrors(SeedAdminUser::class);
    foreach ($errors as $error) {
        echo $error->message;
    }
}
```

### Graceful Degradation

Execute multiple operations and collect failures:

```php
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\Support\OperationDiscovery;

$discovery = app(OperationDiscovery::class);
$pending = $discovery->getPending();
$errors = [];

foreach ($pending as $operationData) {
    try {
        Sequencer::execute($operationData['name']);
    } catch (\Throwable $e) {
        $errors[] = [
            'operation' => $operationData['name'],
            'error' => $e->getMessage(),
        ];
        // Continue to next operation
    }
}

// Report all errors at end
if (!empty($errors)) {
    Log::error('Some operations failed', ['errors' => $errors]);
}
```

## Conditional Execution

The facade automatically respects the `ConditionalExecution` interface:

```php
use Cline\Sequencer\Facades\Sequencer;

// Operation with ConditionalExecution will check shouldRun() automatically
Sequencer::execute(ConditionalOperation::class);

// If shouldRun() returns false, operation is skipped and marked as completed
```

### Environment-Specific Execution

Run operations only in certain environments:

```php
use Cline\Sequencer\Facades\Sequencer;
use Illuminate\Support\Facades\App;

if (App::environment('production')) {
    Sequencer::execute(ProductionOnlyOperation::class);
}

// Or use ConditionalExecution interface in your operation
return new class implements Operation, ConditionalExecution
{
    public function shouldRun(): bool
    {
        return App::environment('production');
    }

    public function handle(): void
    {
        // Production-only logic
    }
};
```

## Rollback Support

### Using the Facade

Rollback operations that implement the `Rollbackable` interface:

```php
use Cline\Sequencer\Facades\Sequencer;

// Rollback by class name
Sequencer::rollback(SeedAdminUser::class);

// Rollback by file name
Sequencer::rollback('2024_01_15_120000_seed_admin_user');

// Rollback without updating database record
Sequencer::rollback(SeedAdminUser::class, record: false);
```

### Batch Rollback

Rollback multiple operations:

```php
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\Database\Models\Operation as OperationModel;

// Get completed operations from today in reverse order
$operations = OperationModel::completed()
    ->today()
    ->latest()
    ->get();

foreach ($operations as $record) {
    try {
        Sequencer::rollback($record->name);
    } catch (\RuntimeException $e) {
        // Operation doesn't implement Rollbackable
        continue;
    } catch (\Throwable $e) {
        Log::error("Failed to rollback {$record->name}", [
            'error' => $e->getMessage(),
        ]);
    }
}
```

## Preview Operations

Preview what operations will execute without running them:

```php
use Cline\Sequencer\Facades\Sequencer;

// Preview all pending operations
$preview = Sequencer::preview();

foreach ($preview as $task) {
    echo "{$task['type']}: {$task['name']}\n";
}

// Preview from specific timestamp
$preview = Sequencer::preview(from: '2024_01_15_120000');

// Preview including already-executed operations
$preview = Sequencer::preview(repeat: true);
```

## Testing

### Faking Operations

Use `OperationFake` to test code that executes operations:

```php
use Cline\Sequencer\Testing\OperationFake;
use Cline\Sequencer\Facades\Sequencer;

test('cronjob executes pending operations', function () {
    OperationFake::setup();

    // Your code that executes operations
    Sequencer::executeAll();

    // Assert operations were dispatched
    OperationFake::assertDispatched(SeedAdminUser::class);
    OperationFake::assertDispatchedTimes(MigrateData::class, 1);
});
```

### Testing Individual Operations

Test operation execution with the facade:

```php
use Cline\Sequencer\Facades\Sequencer;

test('seed admin user operation creates admin', function () {
    expect(User::where('email', 'admin@example.com')->exists())->toBeFalse();

    Sequencer::execute(SeedAdminUser::class);

    expect(User::where('email', 'admin@example.com')->exists())->toBeTrue();
    expect(Sequencer::hasExecuted(SeedAdminUser::class))->toBeTrue();
});
```

## Best Practices

### 1. Use the Facade for Simplicity

The facade provides the cleanest API for most use cases:

```php
use Cline\Sequencer\Facades\Sequencer;

// Simple and clean
Sequencer::executeAll(isolate: true);
Sequencer::execute(SeedAdminUser::class);
```

### 2. Use Isolation in Multi-Server Environments

Always use `isolate: true` when running from cronjobs on multiple servers:

```php
// Prevents concurrent execution across servers
Sequencer::executeAll(isolate: true);
```

### 3. Configure Appropriate Lock Timeouts

Set lock timeouts based on expected operation duration:

```php
// In config/sequencer.php
'execution' => [
    'lock' => [
        'timeout' => 120, // Wait up to 2 minutes for lock
        'ttl' => 600,     // Lock expires after 10 minutes
    ],
],
```

### 4. Log All Executions

Always log operation execution for audit trails:

```php
use Cline\Sequencer\Facades\Sequencer;
use Illuminate\Support\Facades\Log;

Log::info('Starting scheduled operations');
Sequencer::executeAll(isolate: true);
Log::info('Completed scheduled operations');
```

### 5. Handle Failures Gracefully

Don't let a single failed operation break your entire process:

```php
use Cline\Sequencer\Facades\Sequencer;

try {
    Sequencer::executeAll(isolate: true);
} catch (\Throwable $e) {
    Log::critical('Sequencer process failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ]);

    // Alert your monitoring system
    app(AlertService::class)->critical('Sequencer failed');
}
```

### 6. Preview Before Executing

Preview operations before executing in production:

```php
use Cline\Sequencer\Facades\Sequencer;

$preview = Sequencer::preview();

if (count($preview) > 100) {
    Log::warning('Large number of pending operations detected', [
        'count' => count($preview),
    ]);
}

// Proceed with execution
Sequencer::executeAll(isolate: true);
```

### 7. Check Status Before Re-Execution

Avoid running operations multiple times:

```php
use Cline\Sequencer\Facades\Sequencer;

if (!Sequencer::hasExecuted(SeedAdminUser::class)) {
    Sequencer::execute(SeedAdminUser::class);
}
```

## Next Steps

- **[Getting Started](getting-started.md)** - Installation and setup
- **[Basic Usage](basic-usage.md)** - Core operation interfaces
- **[Advanced Usage](advanced-usage.md)** - Transactions, async, observability
- **[Dependencies](dependencies.md)** - Operation ordering
- **[Conditional Execution](conditional-execution.md)** - Runtime conditions
