# Basic Usage

This guide covers the core functionality of Sequencer operations, including interfaces, execution patterns, and common use cases.

## Operation Interfaces

Sequencer provides a hierarchy of interfaces to customize operation behavior:

### Base: Operation

The fundamental interface all operations must implement:

```php
use Cline\Sequencer\Contracts\Operation;

return new class implements Operation
{
    public function handle(): void
    {
        // Your business logic here
    }
};
```

### Idempotent

Mark operations as safe for multiple executions:

```php
use Cline\Sequencer\Contracts\Idempotent;

return new class implements Idempotent
{
    public function handle(): void
    {
        // Use upsert instead of insert
        User::upsert([
            ['email' => 'admin@example.com', 'name' => 'Admin'],
        ], ['email'], ['name']);
    }
};
```

**When to use**: Operations that check state before making changes, or use upsert patterns instead of inserts.

### WithinTransaction

Force database transaction wrapping:

```php
use Cline\Sequencer\Contracts\WithinTransaction;

return new class implements WithinTransaction
{
    public function handle(): void
    {
        // Automatically wrapped in DB::transaction()
        User::create([...]);
        Profile::create([...]);
        Settings::create([...]);
    }
};
```

**Note**: By default, all operations are wrapped in transactions via `auto_transaction` config. Use this interface to explicitly mark critical operations.

### Asynchronous

Queue operations for background execution:

```php
use Cline\Sequencer\Contracts\Asynchronous;

return new class implements Asynchronous
{
    public function handle(): void
    {
        // Executed by queue worker
        $this->sendWelcomeEmails();
        $this->generateReports();
    }

    private function sendWelcomeEmails(): void { }
    private function generateReports(): void { }
};
```

**Requirements**: Queue worker must be running (`php artisan queue:work`)

## Common Patterns

### Data Seeding

```php
use Cline\Sequencer\Contracts\Operation;
use App\Models\Category;

return new class implements Operation
{
    public function handle(): void
    {
        $categories = [
            ['name' => 'Technology', 'slug' => 'technology'],
            ['name' => 'Business', 'slug' => 'business'],
            ['name' => 'Lifestyle', 'slug' => 'lifestyle'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
};
```

### Data Migration

```php
use Cline\Sequencer\Contracts\Operation;
use App\Models\User;
use Illuminate\Support\Str;

return new class implements Operation
{
    public function handle(): void
    {
        // Migrate legacy data to new format
        User::whereNull('uuid')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $user->update(['uuid' => Str::uuid()]);
                }
            });
    }
};
```

### API Integration

```php
use Cline\Sequencer\Contracts\Operation;
use Illuminate\Support\Facades\Http;
use App\Models\Product;

return new class implements Operation
{
    public function handle(): void
    {
        $response = Http::get('https://api.example.com/products');

        foreach ($response->json('data') as $product) {
            Product::updateOrCreate(
                ['external_id' => $product['id']],
                [
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'stock' => $product['stock'],
                ]
            );
        }
    }
};
```

### Cache Warming

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Asynchronous;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;

return new class implements Operation, Asynchronous
{
    public function handle(): void
    {
        $products = Product::with('category', 'images')
            ->active()
            ->get();

        Cache::put('featured_products', $products, now()->addHour());
    }
};
```

### File Operations

```php
use Cline\Sequencer\Contracts\Operation;
use Illuminate\Support\Facades\Storage;
use App\Models\Document;

return new class implements Operation
{
    public function handle(): void
    {
        // Move files from old structure to new
        Document::all()->each(function ($document) {
            $oldPath = "uploads/{$document->filename}";
            $newPath = "documents/{$document->user_id}/{$document->filename}";

            if (Storage::exists($oldPath)) {
                Storage::move($oldPath, $newPath);
                $document->update(['path' => $newPath]);
            }
        });
    }
};
```

## Execution Tracking

Sequencer automatically tracks all operation executions in the `operations` table. The `Operation` model provides expressive query scopes:

```php
use Cline\Sequencer\Database\Models\Operation;

// Status scopes
$completed = Operation::completed()->get();
$failed = Operation::failed()->get();
$pending = Operation::pending()->get();
$rolledBack = Operation::rolledBack()->get();

// Filter by name
$operation = Operation::named(SeedAdminUser::class)->first();

// Date filtering
$today = Operation::today()->get();

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

### Available Query Scopes

- **Status**: `executed()`, `completed()`, `failed()`, `pending()`, `rolledBack()`, `successful()`
- **Type**: `synchronous()`, `asynchronous()`
- **Name**: `named($name)` - Supports wildcards like `'seed_%'`
- **Date**: `today()`, `between($start, $end)`
- **Errors**: `withErrors()`, `withoutErrors()`
- **Ordering**: `latest()`, `oldest()`, `orderedByExecution($direction)`
- **Executor**: `executedBy($model)`

## Error Handling

Failed operations are automatically recorded with full context:

```php
use Cline\Sequencer\Database\Models\OperationError;

// Get errors for specific operation
$operation = Operation::named(MyOperation::class)->first();
$errors = $operation->errors;

// View error details
foreach ($errors as $error) {
    echo $error->exception; // Exception class
    echo $error->message;   // Error message
    echo $error->trace;     // Stack trace
    echo $error->context;   // File, line, code
}
```

## Testing Operations

Use `OperationFake` to test code that dispatches operations:

```php
use Cline\Sequencer\Testing\OperationFake;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    public function test_deployment_runs_operations()
    {
        OperationFake::setup();

        // Trigger your deployment process
        $this->artisan('deploy');

        // Assert operations were dispatched
        OperationFake::assertDispatched(SeedAdminUser::class);
        OperationFake::assertDispatchedTimes(MigrateData::class, 1);
        OperationFake::assertNotDispatched(OptionalOperation::class);
    }
}
```

## Best Practices

### 1. Use Idempotent Operations

Make operations safe to run multiple times:

```php
// Bad - will create duplicate records
User::create(['email' => 'admin@example.com']);

// Good - idempotent
User::firstOrCreate(['email' => 'admin@example.com']);
```

### 2. Chunk Large Datasets

Process large datasets in chunks to avoid memory issues:

```php
User::chunkById(100, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});
```

### 3. Log Important Actions

Add logging for audit trails:

```php
use Illuminate\Support\Facades\Log;

public function handle(): void
{
    Log::info('Starting data migration', ['operation' => static::class]);

    // Your logic

    Log::info('Completed data migration', ['records_updated' => $count]);
}
```

### 4. Validate Before Executing

Check prerequisites before making changes:

```php
public function handle(): void
{
    if (!Schema::hasTable('users')) {
        throw new \RuntimeException('Users table does not exist');
    }

    // Proceed with operation
}
```

## Next Steps

- **[Rollback Support](rollback-support.md)** - Handle failures with automatic rollback
- **[Dependencies](dependencies.md)** - Declare operation dependencies
- **[Conditional Execution](conditional-execution.md)** - Skip operations conditionally
- **[Advanced Usage](advanced-usage.md)** - Transactions, observability, and more
