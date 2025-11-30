# Skip Operations

Sequencer provides two ways to skip operation execution: `ConditionalExecution` for pre-execution decisions and `SkipOperationException` for runtime skip decisions during execution.

## When to Use Each Approach

### ConditionalExecution (Pre-Execution)
Use when you can determine skip necessity **before** execution starts:
- Environment checks
- Feature flag evaluation
- Configuration validation
- Database schema checks

### SkipOperationException (Runtime)
Use when you can only determine skip necessity **during** execution:
- API returns 304 Not Modified
- Record already exists after lock acquired
- External service unavailable after connection attempt
- Data validation fails after fetching

## The SkipOperationException

Operations can throw `SkipOperationException` during execution to signal that work should be skipped. The operation will be marked as skipped (not failed) and logged appropriately.

### Basic Usage

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class implements Operation
{
    public function handle(): void
    {
        throw SkipOperationException::create('Custom skip reason');
    }
};
```

## Common Skip Patterns

### Skip When Record Already Exists

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\SkipOperationException;
use App\Models\User;

return new class implements Operation
{
    public function handle(): void
    {
        // Acquire lock first, then check
        $exists = User::where('email', 'admin@example.com')->exists();

        if ($exists) {
            throw SkipOperationException::recordExists();
        }

        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
    }
};
```

### Skip When External Resource Not Modified

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\SkipOperationException;
use Illuminate\Support\Facades\Http;

return new class implements Operation
{
    public function handle(): void
    {
        $lastSyncHash = cache('external_data_hash');

        $response = Http::get('https://api.example.com/data');

        if ($response->status() === 304) {
            throw SkipOperationException::notModified();
        }

        $currentHash = md5($response->body());

        if ($currentHash === $lastSyncHash) {
            throw SkipOperationException::notModified();
        }

        // Process new data
        $this->processData($response->json());
        cache(['external_data_hash' => $currentHash]);
    }

    private function processData(array $data): void
    {
        // Import logic here
    }
};
```

### Skip When Work Already Completed

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\SkipOperationException;
use Illuminate\Support\Facades\DB;

return new class implements Operation
{
    public function handle(): void
    {
        $completed = DB::table('users')
            ->where('migrated_to_v2', true)
            ->count();

        $total = DB::table('users')->count();

        if ($completed === $total) {
            throw SkipOperationException::alreadyProcessed();
        }

        // Migrate remaining users
        DB::table('users')
            ->where('migrated_to_v2', false)
            ->update(['migrated_to_v2' => true]);
    }
};
```

### Skip Based on Runtime Condition

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\SkipOperationException;
use Illuminate\Support\Facades\DB;

return new class implements Operation
{
    public function handle(): void
    {
        // Check feature flag from database (runtime state)
        $featureEnabled = DB::table('feature_flags')
            ->where('key', 'new_dashboard')
            ->value('enabled');

        if (!$featureEnabled) {
            throw SkipOperationException::conditionNotMet('feature.new_dashboard is disabled');
        }

        // Enable dashboard for all users
        DB::table('users')->update(['dashboard_version' => 'v2']);
    }
};
```

## Static Factory Methods

The `SkipOperationException` provides several convenient static factory methods:

### create()
Generic skip with custom reason:
```php
throw SkipOperationException::create('Custom reason here');
```

### alreadyProcessed()
Work was already completed:
```php
throw SkipOperationException::alreadyProcessed();
```

### notModified()
Resource hasn't changed (e.g., API 304):
```php
throw SkipOperationException::notModified();
```

### recordExists()
Record already exists in database:
```php
throw SkipOperationException::recordExists();
```

### conditionNotMet()
Runtime condition not satisfied:
```php
throw SkipOperationException::conditionNotMet('feature.xyz is disabled');
```

## Comparing ConditionalExecution vs SkipOperationException

### Use ConditionalExecution When:
- Decision can be made **before** execution starts
- No database locks or external calls needed
- Checking static configuration or environment
- Validating schema or table existence

```php
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        // This won't execute if shouldRun() returns false
        User::factory()->count(100)->create();
    }

    public function shouldRun(): bool
    {
        return app()->environment('local'); // Pre-execution check
    }
};
```

### Use SkipOperationException When:
- Decision requires **starting** execution (locks, I/O)
- Need to check remote API status
- Validation requires database queries
- Condition discovered during processing

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class implements Operation
{
    public function handle(): void
    {
        // Must start execution to acquire lock and check
        Cache::lock('import-products')->block(30, function () {
            if ($this->alreadyImported()) {
                throw SkipOperationException::alreadyProcessed();
            }

            $this->importProducts();
        });
    }

    private function alreadyImported(): bool
    {
        return cache('products_imported_at') !== null;
    }

    private function importProducts(): void
    {
        // Import logic
    }
};
```

## Transaction Behavior

When an operation throws `SkipOperationException` inside a transaction, the transaction is **not** rolled back. The skip is treated as a successful execution decision.

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Exceptions\SkipOperationException;
use Illuminate\Support\Facades\DB;

return new class implements Operation, WithinTransaction
{
    public function handle(): void
    {
        // Start transaction
        DB::table('audit_log')->insert(['action' => 'migration_attempt']);

        // Check if migration needed
        if ($this->migrationCompleted()) {
            // Transaction COMMITS (not rolled back)
            throw SkipOperationException::alreadyProcessed();
        }

        // Continue with migration
        $this->runMigration();
    }

    private function migrationCompleted(): bool
    {
        return DB::table('migrations')
            ->where('name', 'v2_schema')
            ->exists();
    }

    private function runMigration(): void
    {
        // Migration logic
    }
};
```

## Logging and Monitoring

Skipped operations are logged to the configured log channel with `info` level (not `error`):

```php
Log::channel('stack')->info('Operation skipped during execution', [
    'operation' => 'App\Operations\MigrateUsers',
    'reason' => 'Operation already processed',
]);
```

Configure the log channel in `config/sequencer.php`:
```php
'errors' => [
    'log_channel' => 'stack', // or 'custom', 'single', etc.
],
```

## Database Tracking

Skipped operations are recorded in the database with `skipped_at` timestamp:

```php
// Check which operations were skipped
use Cline\Sequencer\Database\Models\Operation;

$skipped = Operation::whereNotNull('skipped_at')->get();

foreach ($skipped as $operation) {
    echo "{$operation->name} skipped at {$operation->skipped_at}\n";
}
```

## Best Practices

1. **Use descriptive skip reasons** - Help debugging by providing clear context
2. **Choose the right approach** - ConditionalExecution for pre-checks, SkipOperationException for runtime
3. **Log important skips** - The framework logs automatically, but add context if needed
4. **Don't overuse** - If you're always skipping, reconsider your operation design
5. **Combine with Idempotent** - Skip operations pair well with idempotency patterns

## Complete Example: Idempotent Data Import

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Idempotent;
use Cline\Sequencer\Exceptions\SkipOperationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;

return new class implements Operation, Idempotent
{
    public function handle(): void
    {
        // Acquire lock to prevent concurrent imports
        Cache::lock('product-import', 120)->block(10, function () {
            $this->importProducts();
        });
    }

    private function importProducts(): void
    {
        $lastImportHash = cache('product_import_hash');

        $response = Http::get('https://api.supplier.com/products');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch products');
        }

        $currentHash = md5($response->body());

        // Skip if data hasn't changed
        if ($currentHash === $lastImportHash) {
            throw SkipOperationException::notModified();
        }

        $products = $response->json();

        foreach ($products as $productData) {
            Product::updateOrCreate(
                ['sku' => $productData['sku']],
                [
                    'name' => $productData['name'],
                    'price' => $productData['price'],
                ]
            );
        }

        cache(['product_import_hash' => $currentHash]);
    }
};
```

This operation:
- Uses `Idempotent` for safe re-execution
- Acquires lock before checking state
- Skips if data unchanged (runtime decision)
- Processes import if needed
- Tracks import state for next run
