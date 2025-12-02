# Rollback Support

Sequencer provides automatic rollback support for operations that fail during execution. This ensures database integrity by reversing successfully executed operations when a later operation fails.

## The Rollbackable Interface

Implement the `Rollbackable` interface to enable rollback functionality:

```php
use Cline\Sequencer\Contracts\Rollbackable;
use App\Models\User;

return new class implements Rollbackable
{
    public function handle(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);
    }

    public function rollback(): void
    {
        User::where('email', 'admin@example.com')->delete();
    }
};
```

## How Rollback Works

When an operation fails, Sequencer automatically rolls back all previously executed rollbackable operations in **reverse order**:

```
2024_01_01_000000_create_categories (rollbackable) ✓ Executed
2024_01_02_000000_create_products (rollbackable) ✓ Executed
2024_01_03_000000_seed_invalid_data ✗ FAILED
```

Rollback sequence:

```
1. Roll back: create_products (most recent first)
2. Roll back: create_categories
```

## Rollback Patterns

### Record Creation

```php
use Cline\Sequencer\Contracts\Rollbackable;
use App\Models\Setting;

return new class implements Rollbackable
{
    private array $createdIds = [];

    public function handle(): void
    {
        $settings = [
            ['key' => 'app.name', 'value' => 'My App'],
            ['key' => 'app.timezone', 'value' => 'UTC'],
        ];

        foreach ($settings as $setting) {
            $record = Setting::create($setting);
            $this->createdIds[] = $record->id;
        }
    }

    public function rollback(): void
    {
        Setting::whereIn('id', $this->createdIds)->delete();
    }
};
```

### Record Updates

```php
use Cline\Sequencer\Contracts\Rollbackable;
use App\Models\User;

return new class implements Rollbackable
{
    private array $originalStates = [];

    public function handle(): void
    {
        $users = User::where('status', 'pending')->get();

        foreach ($users as $user) {
            $this->originalStates[$user->id] = $user->status;
            $user->update(['status' => 'active']);
        }
    }

    public function rollback(): void
    {
        foreach ($this->originalStates as $userId => $originalStatus) {
            User::find($userId)?->update(['status' => $originalStatus]);
        }
    }
};
```

### File Operations

```php
use Cline\Sequencer\Contracts\Rollbackable;
use Illuminate\Support\Facades\Storage;

return new class implements Rollbackable
{
    private array $uploadedFiles = [];

    public function handle(): void
    {
        $files = ['logo.png', 'banner.jpg', 'favicon.ico'];

        foreach ($files as $file) {
            $path = Storage::putFile('assets', $file);
            $this->uploadedFiles[] = $path;
        }
    }

    public function rollback(): void
    {
        foreach ($this->uploadedFiles as $path) {
            Storage::delete($path);
        }
    }
};
```

### Database Schema Changes

```php
use Cline\Sequencer\Contracts\Rollbackable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class implements Rollbackable
{
    public function handle(): void
    {
        DB::statement('CREATE INDEX idx_email ON users(email)');
    }

    public function rollback(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_email');
    }
};
```

## Rollback Tracking

Sequencer records rollback execution in the database:

```php
use Cline\Sequencer\Database\Models\Operation;

// Check if operation was rolled back
$operation = Operation::named(MyOperation::class)->first();

if ($operation->rolled_back_at) {
    echo "Operation was rolled back at {$operation->rolled_back_at}";
}
```

## Non-Rollbackable Operations

Not all operations can or should be rolled back. For operations that can't be reversed, omit the `Rollbackable` interface:

```php
use Cline\Sequencer\Contracts\Operation;

// This operation sends emails - can't be rolled back
return new class implements Operation
{
    public function handle(): void
    {
        Mail::to($users)->send(new WelcomeEmail());
    }
};
```

When a non-rollbackable operation is executed before a failure, it won't be reversed (since it can't be), but all rollbackable operations will still roll back.

## Combining with Transactions

Operations are automatically wrapped in database transactions (unless disabled). This provides two layers of protection:

```php
use Cline\Sequencer\Contracts\Rollbackable;
use Cline\Sequencer\Contracts\WithinTransaction;

// Database changes auto-rollback on exception (transaction)
// Plus explicit rollback if operation fails later (rollbackable)
return new class implements Rollbackable, WithinTransaction
{
    public function handle(): void
    {
        // If exception occurs here, DB transaction rolls back automatically
        User::create([...]);
        Profile::create([...]);
    }

    public function rollback(): void
    {
        // If different operation fails later, this rolls back the changes
        User::where('email', '...')->delete();
    }
};
```

## Automatic Rollback with Orchestrators

For automatic rollback of batch operations, use the **TransactionalBatchOrchestrator**:

```php
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\Orchestrators\TransactionalBatchOrchestrator;

// All-or-nothing: if any operation fails, all rollbackable operations roll back
Sequencer::using(TransactionalBatchOrchestrator::class)->executeAll();
```

See **[Orchestration Strategies](orchestration-strategies.md#transactionalbatchorchestrator)** for details on automatic rollback with batch execution.

## Testing Rollback

Test rollback behavior using PHPUnit:

```php
use Tests\TestCase;
use Cline\Sequencer\SequentialOrchestrator;

class RollbackTest extends TestCase
{
    public function test_operations_rollback_on_failure()
    {
        $this->expectException(\RuntimeException::class);

        // Execute operations (will fail and trigger rollback)
        app(SequentialOrchestrator::class)->process();

        // Verify rollback occurred
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }
}
```

## Best Practices

### 1. Keep Rollback Logic Simple

Rollback should reverse the operation without complex logic:

```php
// Good - simple deletion
public function rollback(): void
{
    Setting::where('key', 'app.name')->delete();
}

// Avoid - complex conditional logic
public function rollback(): void
{
    if (/* complex conditions */) {
        // Complex rollback logic
    }
}
```

### 2. Store State for Rollback

Capture necessary data during execution for use in rollback:

```php
private array $changedRecords = [];

public function handle(): void
{
    $records = Model::all();
    foreach ($records as $record) {
        $this->changedRecords[] = [
            'id' => $record->id,
            'old_value' => $record->value,
        ];
        $record->update(['value' => 'new']);
    }
}
```

### 3. Handle Rollback Failures Gracefully

Rollback failures are logged but don't halt the rollback process:

```php
public function rollback(): void
{
    try {
        // Attempt rollback
        $this->deleteRecords();
    } catch (\Exception $e) {
        // Logged automatically by Sequencer
        // Other operations will still roll back
    }
}
```

### 4. Test Rollback Paths

Always test that rollback works correctly:

```php
test('rollback reverses operation', function () {
    $operation = new MyOperation();

    $operation->handle();
    expect(User::count())->toBe(1);

    $operation->rollback();
    expect(User::count())->toBe(0);
});
```

## Next Steps

- **[Dependencies](dependencies.md)** - Declare operation dependencies
- **[Conditional Execution](conditional-execution.md)** - Skip operations conditionally
- **[Advanced Usage](advanced-usage.md)** - Transactions, observability, and more
