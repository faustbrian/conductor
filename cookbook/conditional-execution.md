# Conditional Execution

Sequencer allows operations to determine at runtime whether they should execute using the `ConditionalExecution` interface. This enables environment-specific operations, feature flag gating, and state-based execution.

## The ConditionalExecution Interface

```php
use Cline\Sequencer\Contracts\ConditionalExecution;
use App\Models\User;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        User::create(['name' => 'Demo User']);
    }

    public function shouldRun(): bool
    {
        return app()->environment('local', 'staging');
    }
};
```

If `shouldRun()` returns `false`, Sequencer skips the operation and marks it as completed (since the decision not to run was successful).

## Common Conditional Patterns

### Environment-Based Execution

```php
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        // Production-only data seeding
        Product::factory()->count(1000)->create();
    }

    public function shouldRun(): bool
    {
        return app()->environment('production');
    }
};
```

### Feature Flag Gating

```php
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        // Enable new search index
        Artisan::call('search:reindex');
    }

    public function shouldRun(): bool
    {
        return config('features.new_search_enabled', false);
    }
};
```

### Database State Checks

```php
use Cline\Sequencer\Contracts\ConditionalExecution;
use Illuminate\Support\Facades\Schema;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        User::where('status', 'pending')->update(['status' => 'active']);
    }

    public function shouldRun(): bool
    {
        return Schema::hasColumn('users', 'status');
    }
};
```

### Record Count Checks

```php
use Cline\Sequencer\Contracts\ConditionalExecution;
use App\Models\Category;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        // Seed default categories
        Category::create(['name' => 'General']);
    }

    public function shouldRun(): bool
    {
        return Category::count() === 0;
    }
};
```

### Configuration-Based

```php
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        // Sync with external API
        Http::post('https://api.example.com/sync');
    }

    public function shouldRun(): bool
    {
        return config('services.api.enabled', false);
    }
};
```

### Time-Based Execution

```php
use Cline\Sequencer\Contracts\ConditionalExecution;
use Illuminate\Support\Carbon;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        // Archive old records
        User::where('created_at', '<', now()->subYear())->delete();
    }

    public function shouldRun(): bool
    {
        // Only run on first day of month
        return Carbon::now()->day === 1;
    }
};
```

### Multi-Condition Checks

```php
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class implements ConditionalExecution
{
    public function handle(): void
    {
        $this->sendNotifications();
    }

    public function shouldRun(): bool
    {
        return app()->environment('production')
            && config('mail.enabled')
            && User::count() > 0;
    }
};
```

## Conditional Execution Logging

When an operation is skipped, Sequencer logs the decision:

```
[2024-01-15 12:00:00] local.INFO: Operation skipped by shouldRun() condition
{"operation":"Database\\Operations\\SeedDemoData"}
```

The operation is still recorded in the database with completion timestamp:

```php
use Cline\Sequencer\Database\Models\Operation;

$operation = Operation::named(SeedDemoData::class)->first();

$operation->executed_at;  // When shouldRun() was called
$operation->completed_at; // Set even though skipped
$operation->failed_at;    // null (not failed, just skipped)
```

## Testing Conditional Operations

Test both execution paths:

```php
use Tests\TestCase;
use Cline\Sequencer\Testing\OperationFake;

class ConditionalOperationTest extends TestCase
{
    public function test_operation_runs_in_production()
    {
        app()->detectEnvironment(fn () => 'production');

        $operation = new SeedProductionData();

        $this->assertTrue($operation->shouldRun());
    }

    public function test_operation_skips_in_local()
    {
        app()->detectEnvironment(fn () => 'local');

        $operation = new SeedProductionData();

        $this->assertFalse($operation->shouldRun());
    }

    public function test_orchestrator_skips_conditional_operation()
    {
        app()->detectEnvironment(fn () => 'local');
        OperationFake::setup();

        app(SequentialOrchestrator::class)->process();

        // Operation was checked but not executed
        OperationFake::assertNotDispatched(SeedProductionData::class);
    }
}
```

## Combining with Other Features

### Conditional + Rollbackable

```php
use Cline\Sequencer\Contracts\ConditionalExecution;
use Cline\Sequencer\Contracts\Rollbackable;

return new class implements ConditionalExecution, Rollbackable
{
    public function handle(): void
    {
        User::create(['name' => 'Admin']);
    }

    public function rollback(): void
    {
        User::where('name', 'Admin')->delete();
    }

    public function shouldRun(): bool
    {
        return app()->environment('production');
    }
};
```

### Conditional + Dependencies

```php
use Cline\Sequencer\Contracts\ConditionalExecution;
use Cline\Sequencer\Contracts\HasDependencies;

return new class implements ConditionalExecution, HasDependencies
{
    public function handle(): void
    {
        Product::create([...]);
    }

    public function shouldRun(): bool
    {
        return Category::exists();
    }

    public function dependsOn(): array
    {
        return [SeedCategories::class];
    }
};
```

### Conditional + Idempotent

```php
use Cline\Sequencer\Contracts\ConditionalExecution;
use Cline\Sequencer\Contracts\Idempotent;

return new class implements ConditionalExecution, Idempotent
{
    public function handle(): void
    {
        Setting::updateOrCreate(
            ['key' => 'app.name'],
            ['value' => 'My App']
        );
    }

    public function shouldRun(): bool
    {
        return !Setting::where('key', 'app.name')->exists();
    }
};
```

## Best Practices

### 1. Keep shouldRun() Fast

Avoid expensive operations in condition checks:

```php
// Good - simple check
public function shouldRun(): bool
{
    return app()->environment('production');
}

// Avoid - expensive database query
public function shouldRun(): bool
{
    return User::where('created_at', '>', now()->subYear())->count() > 1000;
}
```

### 2. Make Conditions Explicit

Use clear, descriptive conditions:

```php
// Good - clear intent
public function shouldRun(): bool
{
    return $this->isProductionEnvironment()
        && $this->hasRequiredData();
}

private function isProductionEnvironment(): bool
{
    return app()->environment('production');
}

private function hasRequiredData(): bool
{
    return Category::exists();
}

// Avoid - unclear logic
public function shouldRun(): bool
{
    return env('APP_ENV') === 'production' && Category::count() > 0;
}
```

### 3. Log Condition Decisions

Add logging for debugging:

```php
use Illuminate\Support\Facades\Log;

public function shouldRun(): bool
{
    $shouldRun = app()->environment('production');

    Log::info('Checking execution condition', [
        'operation' => static::class,
        'should_run' => $shouldRun,
        'environment' => app()->environment(),
    ]);

    return $shouldRun;
}
```

### 4. Handle Edge Cases

Account for unexpected states:

```php
public function shouldRun(): bool
{
    try {
        return Setting::where('key', 'feature.enabled')->value('value') === 'true';
    } catch (\Exception $e) {
        // Default to not running if check fails
        Log::warning('Failed to check shouldRun condition', [
            'operation' => static::class,
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}
```

## Dry-Run Preview

Use dry-run to see which operations would be skipped:

```bash
php artisan sequencer:process --dry-run
```

Operations with false `shouldRun()` results are still shown but marked as conditional.

## Next Steps

- **[Advanced Usage](advanced-usage.md)** - Transactions, async operations, and observability
