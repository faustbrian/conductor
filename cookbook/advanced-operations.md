# Advanced Operations

This guide covers advanced operation features including retries, timeouts, batching, chaining, middleware, and unique operations.

## Retry Mechanisms

### Retryable Operations

Implement `Retryable` to define automatic retry behavior:

```php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Retryable;
use Cline\Sequencer\Contracts\Asynchronous;

return new class implements Operation, Asynchronous, Retryable
{
    public function tries(): int
    {
        return 5; // Retry up to 5 times
    }

    public function backoff(): array|int
    {
        return [1, 5, 10, 30, 60]; // Progressive backoff in seconds
    }

    public function retryUntil(): ?\DateTimeInterface
    {
        return now()->addHour(); // Stop retrying after 1 hour
    }

    public function handle(): void
    {
        // Operation that may fail and should retry
        Http::post('https://api.example.com/webhook', [
            'data' => $this->getData(),
        ]);
    }
};
```

### Fixed Backoff

Use a single integer for fixed delay between retries:

```php
public function backoff(): array|int
{
    return 30; // Wait 30 seconds between each retry
}
```

### Exponential Backoff

Use an array for progressive delays:

```php
public function backoff(): array|int
{
    return [1, 2, 4, 8, 16]; // Exponential backoff
}
```

## Timeouts

### Timeoutable Operations

Implement `Timeoutable` to set execution limits:

```php
use Cline\Sequencer\Contracts\Timeoutable;

return new class implements Operation, Asynchronous, Timeoutable
{
    public function timeout(): int
    {
        return 120; // Maximum 2 minutes
    }

    public function failOnTimeout(): bool
    {
        return false; // Retry on timeout
    }

    public function handle(): void
    {
        // Long-running operation
        $this->processLargeDataset();
    }
};
```

### Fail Immediately on Timeout

```php
public function failOnTimeout(): bool
{
    return true; // Mark as failed without retry
}
```

## Batching Operations

**Note**: For orchestrator-level batch execution strategies, see **[Orchestration Strategies](orchestration-strategies.md)**. This includes:
- **BatchOrchestrator** - Parallel execution of all operations
- **TransactionalBatchOrchestrator** - All-or-nothing with automatic rollback
- **AllowedToFailBatchOrchestrator** - Continue on non-critical failures

### Basic Batch

Execute multiple operations in parallel:

```php
use Cline\Sequencer\Facades\Sequencer;

Sequencer::batch([
    ImportUsers::class,
    ImportProducts::class,
    ImportOrders::class,
])->dispatch();
```

### Batch with Callbacks

```php
Sequencer::batch([
    ImportUsers::class,
    ImportProducts::class,
])->then(function (\Illuminate\Bus\Batch $batch) {
    // All operations completed successfully
    Log::info('Batch completed', ['id' => $batch->id]);
})->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) {
    // One or more operations failed
    Log::error('Batch failed', [
        'id' => $batch->id,
        'error' => $e->getMessage(),
    ]);
})->finally(function (\Illuminate\Bus\Batch $batch) {
    // Cleanup regardless of success/failure
    Cache::forget("batch-{$batch->id}");
})->dispatch();
```

### Batchable Operations

Operations can interact with their batch context:

```php
use Cline\Sequencer\Concerns\Batchable;

return new class implements Operation, Asynchronous
{
    use Batchable;

    public function handle(): void
    {
        // Check if batch was cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Perform work
        $this->processData();

        // Dynamically add more operations to batch
        $this->addBatch([
            new ProcessRelatedData(),
        ]);
    }
};
```

## Chaining Operations

### Sequential Execution

Execute operations one after another:

```php
use Cline\Sequencer\Facades\Sequencer;

Sequencer::chain([
    ProcessPodcast::class,
    OptimizePodcast::class,
    ReleasePodcast::class,
])->dispatch();
```

### Chain with Configuration

```php
Sequencer::chain([
    ProcessPodcast::class,
    OptimizePodcast::class,
])->onConnection('redis')
  ->onQueue('podcasts')
  ->catch(function (\Throwable $e) {
      // Handle chain failure
      Log::error('Chain failed', ['error' => $e->getMessage()]);
  })
  ->dispatch();
```

## Middleware

### Rate Limiting

Limit how often an operation can execute:

```php
use Cline\Sequencer\Contracts\HasMiddleware;
use Illuminate\Queue\Middleware\RateLimited;

return new class implements Operation, Asynchronous, HasMiddleware
{
    public function middleware(): array
    {
        return [new RateLimited('api-calls')];
    }

    public function handle(): void
    {
        // This operation is rate-limited
        Api::processWebhook($this->data);
    }
};
```

Define rate limits in your service provider:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('api-calls', function ($operation) {
    return Limit::perMinute(60);
});
```

### Prevent Overlapping Execution

```php
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping($this->userId))
            ->releaseAfter(60)
            ->expireAfter(180)
    ];
}
```

### Throttle Exceptions

```php
use Illuminate\Queue\Middleware\ThrottlesExceptions;

public function middleware(): array
{
    return [
        (new ThrottlesExceptions(10, 5 * 60))
            ->backoff(5)
            ->by('import-users')
    ];
}
```

## Unique Operations

### Prevent Duplicate Execution

Ensure only one instance of an operation runs at a time:

```php
use Cline\Sequencer\Contracts\ShouldBeUnique;

return new class implements Operation, Asynchronous, ShouldBeUnique
{
    public function uniqueId(): string
    {
        return "process-user-{$this->userId}";
    }

    public function uniqueFor(): int
    {
        return 3600; // Lock for 1 hour
    }

    public function uniqueVia(): ?\Illuminate\Contracts\Cache\Repository
    {
        return Cache::driver('redis');
    }

    public function handle(): void
    {
        // Only one instance per user can run at a time
        $this->processUserData($this->userId);
    }
};
```

## Conditional Dispatch

### Dispatch If Condition Met

```php
use Cline\Sequencer\Facades\Sequencer;

// Execute only if condition is true
Sequencer::executeIf(
    $user->isPremium(),
    ProcessPremiumFeatures::class
);

// Execute unless condition is true
Sequencer::executeUnless(
    $user->hasProcessedToday(),
    ProcessDailyReport::class
);
```

### Synchronous Execution

Force synchronous execution regardless of `Asynchronous` interface:

```php
// Execute immediately, bypassing queue
Sequencer::executeSync(ProcessUrgentTask::class);
```

## Exception Handling

### Max Exceptions

Limit failures based on exception count rather than retry attempts:

```php
use Cline\Sequencer\Contracts\HasMaxExceptions;

return new class implements Operation, Asynchronous, Retryable, HasMaxExceptions
{
    public function tries(): int
    {
        return 10; // Try up to 10 times
    }

    public function maxExceptions(): int
    {
        return 3; // But fail after 3 actual exceptions
    }

    public function handle(): void
    {
        // May fail occasionally due to network issues
        // Will retry up to 10 times but fail permanently after 3 exceptions
        $this->syncWithExternalApi();
    }
};
```

This is useful when you want to distinguish between expected retries (like network timeouts) and actual errors.

## Encryption

### Encrypted Operations

Automatically encrypt sensitive operation data:

```php
use Cline\Sequencer\Contracts\ShouldBeEncrypted;

return new class implements Operation, Asynchronous, ShouldBeEncrypted
{
    public function __construct(
        private string $apiKey,
        private string $userToken,
    ) {
    }

    public function handle(): void
    {
        // Operation payload is automatically encrypted in queue
        Api::authenticate($this->apiKey, $this->userToken);
    }
};
```

The operation's serialized data is encrypted before being pushed to the queue and decrypted when retrieved.

## Operation Tags

### Tagging for Monitoring

Add tags to operations for filtering and metrics:

```php
use Cline\Sequencer\Contracts\HasTags;

return new class implements Operation, Asynchronous, HasTags
{
    public function tags(): array
    {
        return [
            'user-import',
            'priority:high',
            "tenant:{$this->tenantId}",
        ];
    }

    public function handle(): void
    {
        // Tags visible in queue monitoring tools
        $this->importUsers();
    }
};
```

Tags are useful for:
- Filtering operations in monitoring dashboards
- Grouping metrics by operation type
- Debugging specific operation subsets
- Identifying high-priority operations

## Lifecycle Hooks

### Before, After, and Failed Callbacks

Execute custom logic at specific points in the operation lifecycle:

```php
use Cline\Sequencer\Contracts\HasLifecycleHooks;

return new class implements Operation, Asynchronous, HasLifecycleHooks
{
    public function before(): void
    {
        // Executed before operation starts
        Log::info('Starting user import', ['batch' => $this->batchId]);
        Cache::put("import-{$this->batchId}-status", 'running');
    }

    public function handle(): void
    {
        // Main operation logic
        $this->importUsers();
    }

    public function after(): void
    {
        // Executed after successful completion
        Log::info('User import completed', ['batch' => $this->batchId]);
        Cache::put("import-{$this->batchId}-status", 'completed');

        // Notify administrators
        Notification::send($this->admins, new ImportCompleted($this->batchId));
    }

    public function failed(\Throwable $exception): void
    {
        // Executed when operation fails
        Log::error('User import failed', [
            'batch' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);
        Cache::put("import-{$this->batchId}-status", 'failed');

        // Clean up resources
        Storage::delete("import-{$this->batchId}.csv");
    }
};
```

Lifecycle hooks are useful for:
- Logging and monitoring
- Resource cleanup
- State tracking
- Notifications
- Metrics collection

## Combining Features

### Robust API Integration

```php
use Cline\Sequencer\Contracts\{
    Operation,
    Asynchronous,
    Retryable,
    Timeoutable,
    HasMiddleware,
    ShouldBeUnique,
    HasMaxExceptions,
    HasTags,
    HasLifecycleHooks
};
use Illuminate\Queue\Middleware\{RateLimited, ThrottlesExceptions};

return new class implements
    Operation,
    Asynchronous,
    Retryable,
    Timeoutable,
    HasMiddleware,
    ShouldBeUnique,
    HasMaxExceptions,
    HasTags,
    HasLifecycleHooks
{
    // Retry configuration
    public function tries(): int
    {
        return 3;
    }

    public function backoff(): array|int
    {
        return [10, 30, 60];
    }

    public function retryUntil(): ?\DateTimeInterface
    {
        return now()->addHour();
    }

    // Timeout configuration
    public function timeout(): int
    {
        return 60;
    }

    public function failOnTimeout(): bool
    {
        return false;
    }

    // Max exceptions
    public function maxExceptions(): int
    {
        return 5; // Fail after 5 actual exceptions
    }

    // Middleware
    public function middleware(): array
    {
        return [
            new RateLimited('external-api'),
            (new ThrottlesExceptions(5, 300))->backoff(30),
        ];
    }

    // Uniqueness
    public function uniqueId(): string
    {
        return "sync-customer-{$this->customerId}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function uniqueVia(): ?\Illuminate\Contracts\Cache\Repository
    {
        return Cache::driver('redis');
    }

    // Tags
    public function tags(): array
    {
        return ['api-sync', 'customer', "tenant:{$this->tenantId}"];
    }

    // Lifecycle hooks
    public function before(): void
    {
        Log::info('Starting customer sync', ['customer' => $this->customerId]);
        Cache::put("sync-{$this->customerId}", 'running', 600);
    }

    public function after(): void
    {
        Log::info('Customer sync completed', ['customer' => $this->customerId]);
        Cache::put("sync-{$this->customerId}", 'completed', 3600);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Customer sync failed', [
            'customer' => $this->customerId,
            'error' => $exception->getMessage(),
        ]);
        Cache::forget("sync-{$this->customerId}");
    }

    // Execution
    public function handle(): void
    {
        // Reliable external API integration with comprehensive error handling
        $response = Http::post('https://api.example.com/sync', [
            'customer_id' => $this->customerId,
            'data' => $this->getData(),
        ]);

        $this->processResponse($response);
    }
};
```

## Best Practices

### 1. Always Set Retry Limits

Never allow unlimited retries:

```php
public function retryUntil(): ?\DateTimeInterface
{
    return now()->addHours(24); // Max 24 hours of retries
}
```

### 2. Use Exponential Backoff

Reduce load during outages:

```php
public function backoff(): array|int
{
    return [1, 5, 15, 30, 60]; // Progressive delays
}
```

### 3. Set Appropriate Timeouts

Prevent indefinite execution:

```php
public function timeout(): int
{
    return 300; // 5 minutes max
}
```

### 4. Rate Limit External APIs

Respect API limits:

```php
RateLimiter::for('external-api', function () {
    return Limit::perMinute(60)->by('external-api');
});
```

### 5. Use Unique Operations for Critical Tasks

Prevent duplicate processing:

```php
public function uniqueId(): string
{
    return "process-payment-{$this->paymentId}";
}
```

## Next Steps

- **[Getting Started](getting-started.md)** - Installation and setup
- **[Basic Usage](basic-usage.md)** - Core operation interfaces
- **[Programmatic Usage](programmatic-usage.md)** - Facade API
- **[Advanced Usage](advanced-usage.md)** - Transactions, observability
