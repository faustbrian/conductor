# Advanced Usage

This guide covers advanced Sequencer features including transaction control, asynchronous operations, observability integration, and multi-server deployments.

## Transaction Control

### Auto-Transaction Configuration

By default, all operations execute within database transactions:

```php
// config/sequencer.php
'execution' => [
    'auto_transaction' => env('SEQUENCER_AUTO_TRANSACTION', true),
],
```

Disable globally:

```env
SEQUENCER_AUTO_TRANSACTION=false
```

### Per-Operation Transaction Control

Explicitly request transaction wrapping:

```php
use Cline\Sequencer\Contracts\WithinTransaction;

return new class implements WithinTransaction
{
    public function handle(): void
    {
        // Wrapped in DB::transaction() regardless of global config
        User::create([...]);
        Profile::create([...]);
    }
};
```

Opt-out of auto-transaction for specific operations:

```php
use Cline\Sequencer\Contracts\Operation;

// Note: Does NOT implement WithinTransaction
return new class implements Operation
{
    public function handle(): void
    {
        // Runs without transaction when auto_transaction is false
        // Or runs with transaction when auto_transaction is true
    }
};
```

### Manual Transaction Control

For fine-grained control, disable auto-transaction and manage manually:

```php
use Illuminate\Support\Facades\DB;

return new class implements Operation
{
    public function handle(): void
    {
        // First transaction
        DB::transaction(function () {
            User::create([...]);
        });

        // Do something outside transaction
        Log::info('User created');

        // Second transaction
        DB::transaction(function () {
            Profile::create([...]);
        });
    }
};
```

## Executing Single Operations

### Running Individual Operations

Execute a specific operation by timestamp or class name using the `sequencer:execute` command:

```bash
# Execute by timestamp
php artisan sequencer:execute 2024_01_01_120000_my_operation

# Execute by class name
php artisan sequencer:execute App\\Operations\\MyOperation
```

### Execution Modes

Control how the operation executes:

```bash
# Force synchronous execution (default)
php artisan sequencer:execute 2024_01_01_120000_my_operation --sync

# Force asynchronous execution via queue
php artisan sequencer:execute 2024_01_01_120000_my_operation --async

# Execute without creating database record
php artisan sequencer:execute 2024_01_01_120000_my_operation --no-record
```

### Custom Queue Routing

Dispatch to a specific queue when using `--async`:

```bash
# Route to high-priority queue
php artisan sequencer:execute 2024_01_01_120000_my_operation --async --queue=high-priority

# Route to low-priority queue
php artisan sequencer:execute 2024_01_01_120000_my_operation --async --queue=low-priority
```

**Important**: The `--queue` flag only works with `--async`. If you specify `--queue` without `--async`, you'll see a warning.

### Validation

Mutually exclusive flags are validated:

```bash
# This will fail
php artisan sequencer:execute 2024_01_01_120000_my_operation --sync --async
# Error: Cannot use --sync and --async together
```

### Use Cases

**Development & Debugging**:
```bash
# Re-run a specific operation during development
php artisan sequencer:execute 2024_01_15_120000_seed_test_data --no-record

# Debug an operation synchronously instead of via queue
php artisan sequencer:execute 2024_01_15_120000_my_operation --sync
```

**Production Operations**:
```bash
# Execute critical operation with high priority
php artisan sequencer:execute 2024_01_15_120000_critical_fix --async --queue=critical

# Run maintenance operation without tracking
php artisan sequencer:execute 2024_01_15_120000_cache_warm --no-record
```

## Asynchronous Operations

Queue operations for background execution:

```php
use Cline\Sequencer\Contracts\Asynchronous;

return new class implements Asynchronous
{
    public function handle(): void
    {
        // Executes via queue worker
        $this->processLargeDataset();
        $this->sendNotifications();
    }

    private function processLargeDataset(): void
    {
        User::chunk(1000, function ($users) {
            // Process each chunk
        });
    }
};
```

### Queue Configuration

Configure queue connection and queue name:

```php
// config/sequencer.php
'queue' => [
    'connection' => env('SEQUENCER_QUEUE_CONNECTION', 'redis'),
    'queue' => env('SEQUENCER_QUEUE', 'default'),
],
```

In `.env`:

```env
SEQUENCER_QUEUE_CONNECTION=redis
SEQUENCER_QUEUE=operations
```

### Queue Worker

Start queue worker for async operations:

```bash
php artisan queue:work --queue=operations
```

### Force Synchronous/Asynchronous Execution

Override operation's execution mode at runtime:

```bash
# Force all operations to run synchronously (ignore Asynchronous interface)
php artisan sequencer:process --sync

# Force all operations to run asynchronously via queue
php artisan sequencer:process --async
```

**Use cases**:
- **`--sync`**: Debug async operations locally, avoid queue worker requirement
- **`--async`**: Queue normally-sync operations during off-peak hours

**Important**: Cannot use both `--sync` and `--async` together.

### Custom Queue Routing

Dispatch operations to specific queues:

```bash
# Route to high-priority queue
php artisan sequencer:process --queue=high-priority

# Route to low-priority queue
php artisan sequencer:process --queue=low-priority
```

This overrides the configured queue name in `config/sequencer.php` for the current execution.

### Per-Operation Queue Routing

Operations can specify their own queue via the `SpecifiesQueue` interface:

```php
use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\SpecifiesQueue;

return new class implements Asynchronous, SpecifiesQueue
{
    public function queue(): string
    {
        return 'high-priority';
    }

    public function handle(): void
    {
        // This operation will be dispatched to the 'high-priority' queue
        $this->processUrgentTask();
    }
};
```

**Queue Priority Order** (highest to lowest):
1. CLI `--queue` flag (overrides everything)
2. Operation's `queue()` method (`SpecifiesQueue` interface)
3. Global config (`config/sequencer.php`)

**Example Priority Scenarios**:

```php
// Operation defines 'high-priority' queue
return new class implements Asynchronous, SpecifiesQueue
{
    public function queue(): string { return 'high-priority'; }
};

// CLI override takes precedence
php artisan sequencer:process --queue=low-priority
// ↳ Dispatched to 'low-priority' (not 'high-priority')

// No CLI flag, operation queue used
php artisan sequencer:process
// ↳ Dispatched to 'high-priority'

// No interface, config used
config(['sequencer.queue.queue' => 'default']);
// ↳ Dispatched to 'default'
```

### Async + Rollbackable

Asynchronous operations can still be rollbackable:

```php
use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Rollbackable;

return new class implements Asynchronous, Rollbackable
{
    public function handle(): void
    {
        // Queued execution
        $this->importData();
    }

    public function rollback(): void
    {
        // Synchronous rollback
        $this->deleteImportedData();
    }
};
```

## Observability

### Laravel Pulse Integration

Enable Pulse recording for real-time monitoring:

```env
SEQUENCER_PULSE_ENABLED=true
```

Pulse records:
- Operation started
- Operation completed
- Operation failed
- Operation rolled back

### Laravel Telescope Integration

Enable Telescope integration:

```env
SEQUENCER_TELESCOPE_ENABLED=true
```

Telescope records all operation events with full context for debugging.

### Custom Event Observers

Register custom observers:

```php
use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Observers\SequencerObserver;

// In AppServiceProvider
Operation::observe(SequencerObserver::class);
```

### Logging

Configure log channel:

```php
// config/sequencer.php
'errors' => [
    'log_channel' => env('SEQUENCER_LOG_CHANNEL', 'stack'),
],
```

Logged events:
- Operation execution start
- Operation completion
- Operation failure (with full stack trace)
- Operation skipped (conditional execution)
- Rollback success/failure

## Multi-Server Deployments

### Atomic Locking

Prevent concurrent execution across servers:

```bash
php artisan sequencer:process --isolate
```

### Lock Configuration

Configure lock store and timeouts:

```php
// config/sequencer.php
'execution' => [
    'lock' => [
        'store' => env('SEQUENCER_LOCK_STORE', 'redis'),
        'timeout' => env('SEQUENCER_LOCK_TIMEOUT', 60),
        'ttl' => env('SEQUENCER_LOCK_TTL', 600),
    ],
],
```

- **store**: Cache store for locks (redis recommended)
- **timeout**: Seconds to wait for lock acquisition
- **ttl**: Maximum seconds lock can be held

### Lock Failure Handling

If lock cannot be acquired within timeout:

```
RuntimeException: Could not acquire sequencer lock within timeout period
```

Increase timeout or check for stuck locks:

```php
use Illuminate\Support\Facades\Cache;

// Clear stuck lock (only if necessary!)
Cache::store('redis')->forget('sequencer:process');
```

## Partial Execution

Resume execution from specific timestamp after failure:

```bash
php artisan sequencer:process --from=2024_01_15_120000
```

Use cases:
- **Recovery**: Resume after fixing failed operation
- **Incremental rollout**: Deploy operations in stages
- **Testing**: Re-run operations from specific point

## Re-executing Completed Operations

The `--repeat` flag allows you to re-execute operations that have already been completed. This is more explicit than `--force` and ensures operations have been executed at least once before:

```bash
php artisan sequencer:process --repeat
```

**Important**: The `--repeat` flag will throw an exception if any discovered operation has never been executed before. This safety check ensures you're only re-running operations that have a successful execution history.

### Use Cases

**Data refresh**: Re-run data seeding operations after clearing the database:

```bash
# Clear specific data
php artisan db:seed --class=ClearOldDataSeeder

# Re-execute operations to rebuild
php artisan sequencer:process --repeat
```

**Testing**: Verify operations are idempotent by running them multiple times:

```bash
# First run
php artisan sequencer:process

# Verify idempotency
php artisan sequencer:process --repeat
```

**Recovery**: Re-execute operations after fixing implementation bugs:

```bash
# Fix operation code
# Re-run to apply corrected logic
php artisan sequencer:process --repeat
```

### Combining with --from

You can combine `--repeat` with `--from` to re-execute a subset of operations:

```bash
php artisan sequencer:process --repeat --from=2024_01_15_120000
```

This will re-execute all operations from the specified timestamp onwards, but only if they've all been executed before.

### Safety Guarantees

The `--repeat` flag ensures:
1. **No accidental re-runs**: Operations must have been executed before
2. **Explicit intent**: More clear than `--force` that you're repeating an operation
3. **Batch safety**: All discovered operations must have execution history

## Multiple Discovery Paths

Configure multiple operation directories:

```php
// config/sequencer.php
'execution' => [
    'discovery_paths' => [
        database_path('operations'),
        database_path('migrations/operations'),
        app_path('Operations'),
    ],
],
```

Operations from all paths are discovered and sorted together by timestamp.

## Tag-Based Filtering

### Tagging Operations

Add tags to operations for categorization and selective execution:

```php
use Cline\Sequencer\Contracts\HasTags;
use Cline\Sequencer\Contracts\Operation;

return new class implements Operation, HasTags
{
    public function handle(): void
    {
        // Migration logic
    }

    public function tags(): array
    {
        return [
            'data-migration',
            'user-management',
            'production-safe',
        ];
    }
};
```

### Filtering by Tags

Execute only operations with specific tags:

```bash
# Run only billing operations
php artisan sequencer:process --tags=billing

# Run operations with multiple tags (OR logic)
php artisan sequencer:process --tags=billing --tags=critical

# Combine with other flags
php artisan sequencer:process --tags=data-migration --async --queue=migrations
```

**Important**:
- Migrations are always included regardless of tags
- Operations without the `HasTags` interface are excluded when using `--tags`
- Tag matching uses OR logic (operation needs at least one matching tag)

### Common Tagging Strategies

**Functional categories**:
```php
return ['data-migration', 'cache-warming', 'notifications'];
```

**Environment indicators**:
```php
return ['production-only', 'staging-safe', 'development'];
```

**Business domains**:
```php
return ['billing', 'analytics', 'user-management'];
```

**Priority levels**:
```php
return ['critical', 'standard', 'low-priority'];
```

**Feature flags**:
```php
return ['new-feature', 'experimental', 'legacy-cleanup'];
```

### Dry-Run with Tags

Preview which operations match your tag filter:

```bash
php artisan sequencer:process --dry-run --tags=billing
```

This shows exactly which operations will run before executing them.

## Error Recording

### Automatic Error Recording

Failed operations are automatically recorded with full context:

```php
use Cline\Sequencer\Database\Models\OperationError;

$operation = Operation::named(MyOperation::class)->first();

foreach ($operation->errors as $error) {
    echo $error->exception;  // Exception class
    echo $error->message;    // Error message
    echo $error->trace;      // Full stack trace
    echo $error->context;    // ['file' => '...', 'line' => 123, 'code' => 0]
}
```

### Disable Error Recording

```env
SEQUENCER_RECORD_ERRORS=false
```

Errors are still logged but not stored in database.

## Custom Primary Keys

### ULID Configuration

```env
SEQUENCER_PRIMARY_KEY_TYPE=ulid
```

Tables use ULIDs instead of auto-increment:

```php
$operation = Operation::create([...]);
echo $operation->id; // "01H3X2Y4Z6ABCDEFGHIJKLMNOP"
```

### UUID Configuration

```env
SEQUENCER_PRIMARY_KEY_TYPE=uuid
```

## Polymorphic Relationships

Configure polymorphic type for `executed_by`:

```env
SEQUENCER_MORPH_TYPE=uuidMorph
```

Options:
- `morph` - Standard Laravel morphs
- `uuidMorph` - UUID polymorphic keys
- `ulidMorph` - ULID polymorphic keys
- `numericMorph` - Numeric polymorphic keys

### Morph Key Mapping

Map models to specific key columns:

```php
// config/sequencer.php
'morphKeyMap' => [
    App\Models\User::class => 'id',
    App\Models\Admin::class => 'uuid',
],
```

### Enforce Morph Key Map

Strictly enforce mapping (throw exception if unmapped model used):

```php
'enforceMorphKeyMap' => [
    App\Models\User::class => 'id',
],
```

## Performance Optimization

### Chunk Large Operations

Process large datasets in chunks:

```php
public function handle(): void
{
    User::chunkById(1000, function ($users) {
        foreach ($users as $user) {
            // Process user
        }
    });
}
```

### Lazy Loading Prevention

Use `chunkById` instead of `chunk` to prevent memory issues with relationships:

```php
User::with('profile')->chunkById(100, function ($users) {
    // Efficiently process with relationships
});
```

### Queue Long-Running Operations

Mark time-consuming operations as asynchronous:

```php
use Cline\Sequencer\Contracts\Asynchronous;

// Runs in background, doesn't block deployment
return new class implements Asynchronous
{
    public function handle(): void
    {
        // Time-consuming work
        $this->generateReports();
        $this->reindexSearch();
    }
};
```

## Testing Advanced Features

### Testing Transactions

```php
use Illuminate\Support\Facades\DB;

test('operation runs in transaction', function () {
    DB::beginTransaction();

    $operation = new MyOperation();
    $operation->handle();

    // Changes are visible within transaction
    expect(User::count())->toBe(1);

    DB::rollBack();

    // Changes rolled back
    expect(User::count())->toBe(0);
});
```

### Testing Async Operations

```php
use Illuminate\Support\Facades\Queue;

test('operation is queued', function () {
    Queue::fake();

    $operation = new AsyncOperation();

    // Operation marked as async
    expect($operation)->toBeInstanceOf(Asynchronous::class);

    Queue::assertPushed(ExecuteOperation::class);
});
```

### Testing Lock Behavior

```php
use Illuminate\Support\Facades\Cache;

test('isolate prevents concurrent execution', function () {
    $lock = Cache::lock('sequencer:process', 10);
    $lock->get();

    $this->expectException(\RuntimeException::class);

    app(SequentialOrchestrator::class)->process(isolate: true);

    $lock->release();
});
```

## Best Practices

### 1. Use Transactions for Data Integrity

Always use transactions for operations modifying multiple tables:

```php
use Cline\Sequencer\Contracts\WithinTransaction;

return new class implements WithinTransaction
{
    public function handle(): void
    {
        User::create([...]);
        Profile::create([...]);
        Settings::create([...]);
    }
};
```

### 2. Queue Heavy Operations

Don't block deployment with long-running tasks:

```php
// Good - queued
class ReindexSearch implements Asynchronous { }

// Avoid - blocks deployment
class ReindexSearch implements Operation { }
```

### 3. Monitor with Pulse/Telescope

Enable observability in production:

```env
SEQUENCER_PULSE_ENABLED=true
SEQUENCER_TELESCOPE_ENABLED=false  # Only in staging
```

### 4. Use Isolation in Production

Always use `--isolate` in multi-server environments:

```bash
php artisan sequencer:process --isolate
```

### 5. Configure Appropriate Timeouts

Set lock timeouts based on expected operation duration:

```env
SEQUENCER_LOCK_TIMEOUT=120  # For long-running operations
```

## Next Steps

You've now mastered Sequencer! Review:

- **[Getting Started](getting-started.md)** - Installation and setup
- **[Basic Usage](basic-usage.md)** - Core operations
- **[Rollback Support](rollback-support.md)** - Failure recovery
- **[Dependencies](dependencies.md)** - Operation ordering
- **[Conditional Execution](conditional-execution.md)** - Runtime conditions
