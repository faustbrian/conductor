# Operation Events

Sequencer dispatches events at key points in the operation lifecycle, mirroring Laravel's migration events. These events allow you to hook into operation execution for logging, monitoring, metrics collection, or custom workflows.

## Available Events

All events are located in the `Cline\Sequencer\Events` namespace.

### Batch-Level Events

#### OperationsStarted

Dispatched when a batch of operations is about to be executed.

```php
use Cline\Sequencer\Events\OperationsStarted;
use Illuminate\Support\Facades\Event;

Event::listen(OperationsStarted::class, function (OperationsStarted $event) {
    Log::info('Operations batch starting', [
        'method' => $event->method, // ExecutionMethod enum
    ]);
});
```

**Properties:**
- `method` (ExecutionMethod): The execution method enum (Sync|Async|Batch|Chain)

#### OperationsEnded

Dispatched when a batch of operations has finished executing.

```php
use Cline\Sequencer\Events\OperationsEnded;

Event::listen(OperationsEnded::class, function (OperationsEnded $event) {
    Log::info('Operations batch completed', [
        'method' => $event->method,
    ]);

    // Clean up resources, send notifications, etc.
    Cache::forget('operations-running');
});
```

**Properties:**
- `method` (ExecutionMethod): The execution method enum (Sync|Async|Batch|Chain)

#### NoPendingOperations

Dispatched when an operation command found no pending operations to execute.

```php
use Cline\Sequencer\Events\NoPendingOperations;

Event::listen(NoPendingOperations::class, function (NoPendingOperations $event) {
    Log::debug('No pending operations found', [
        'method' => $event->method,
    ]);
});
```

**Properties:**
- `method` (ExecutionMethod): The execution method enum (Sync|Async|Batch|Chain)

### Operation-Level Events

#### OperationStarted

Dispatched when a single operation is about to be executed.

```php
use Cline\Sequencer\Events\OperationStarted;

Event::listen(OperationStarted::class, function (OperationStarted $event) {
    Log::info('Operation starting', [
        'operation' => get_class($event->operation),
        'method' => $event->method,
    ]);

    // Start timer for metrics
    Cache::put(
        "operation-start-".spl_object_id($event->operation),
        now(),
        3600
    );
});
```

**Properties:**
- `operation` (Operation): The operation instance being executed
- `method` (ExecutionMethod): The execution method enum (Sync|Async|Batch|Chain)

#### OperationEnded

Dispatched when a single operation has finished executing successfully.

```php
use Cline\Sequencer\Events\OperationEnded;

Event::listen(OperationEnded::class, function (OperationEnded $event) {
    $startTime = Cache::get("operation-start-".spl_object_id($event->operation));
    $duration = $startTime ? now()->diffInMilliseconds($startTime) : null;

    Log::info('Operation completed', [
        'operation' => get_class($event->operation),
        'method' => $event->method,
        'duration_ms' => $duration,
    ]);

    // Send metrics to monitoring service
    Metrics::increment('operations.completed', [
        'type' => get_class($event->operation),
    ]);
});
```

**Properties:**
- `operation` (Operation): The operation instance that completed
- `method` (ExecutionMethod): The execution method enum (Sync|Async|Batch|Chain)

## Event Inheritance

Single-operation events (`OperationStarted` and `OperationEnded`) extend the abstract `OperationEvent` base class, providing consistent access to the operation instance and execution method.

```php
use Cline\Sequencer\Events\OperationEvent;

// Both OperationStarted and OperationEnded extend OperationEvent
abstract class OperationEvent
{
    public readonly Operation $operation;
    public readonly string $method;
}
```

## Common Use Cases

### 1. Performance Monitoring

Track operation execution time and report to monitoring services:

```php
use Cline\Sequencer\Events\{OperationStarted, OperationEnded};

class OperationPerformanceMonitor
{
    private array $timers = [];

    public function handleStarted(OperationStarted $event): void
    {
        $this->timers[spl_object_id($event->operation)] = microtime(true);
    }

    public function handleEnded(OperationEnded $event): void
    {
        $objectId = spl_object_id($event->operation);
        $duration = (microtime(true) - $this->timers[$objectId]) * 1000;

        Metrics::histogram('operation.duration', $duration, [
            'operation' => get_class($event->operation),
            'method' => $event->method,
        ]);

        unset($this->timers[$objectId]);
    }
}

// In EventServiceProvider
Event::listen(OperationStarted::class, [OperationPerformanceMonitor::class, 'handleStarted']);
Event::listen(OperationEnded::class, [OperationPerformanceMonitor::class, 'handleEnded']);
```

### 2. Audit Logging

Create detailed audit trails of operation execution:

```php
use Cline\Sequencer\Events\{OperationsStarted, OperationStarted, OperationEnded, OperationsEnded};

Event::listen(OperationsStarted::class, function ($event) {
    AuditLog::create([
        'event' => 'operations_batch_started',
        'method' => $event->method,
        'user_id' => auth()->id(),
        'timestamp' => now(),
    ]);
});

Event::listen(OperationEnded::class, function ($event) {
    AuditLog::create([
        'event' => 'operation_completed',
        'operation' => get_class($event->operation),
        'method' => $event->method,
        'user_id' => auth()->id(),
        'timestamp' => now(),
    ]);
});
```

### 3. Real-Time Notifications

Notify users of operation progress via websockets:

```php
use Cline\Sequencer\Events\{OperationStarted, OperationEnded};

Event::listen(OperationStarted::class, function ($event) {
    broadcast(new OperationStartedBroadcast(
        operation: get_class($event->operation),
        method: $event->method
    ))->toOthers();
});

Event::listen(OperationEnded::class, function ($event) {
    broadcast(new OperationCompletedBroadcast(
        operation: get_class($event->operation),
        method: $event->method
    ))->toOthers();
});
```

### 4. Resource Management

Prepare and clean up resources around operation execution:

```php
use Cline\Sequencer\Events\{OperationsStarted, OperationsEnded};

Event::listen(OperationsStarted::class, function ($event) {
    // Allocate resources
    Cache::put('operations-running', true, 3600);
    DB::statement('SET SESSION query_timeout = 300000'); // 5 minutes
});

Event::listen(OperationsEnded::class, function ($event) {
    // Clean up resources
    Cache::forget('operations-running');
    DB::statement('SET SESSION query_timeout = DEFAULT');
});
```

### 5. Conditional Workflow Triggers

Trigger follow-up operations based on completion:

```php
use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Facades\Sequencer;

Event::listen(OperationEnded::class, function ($event) {
    // Trigger dependent operation after user import completes
    if ($event->operation instanceof ImportUsers) {
        Sequencer::execute(SendWelcomeEmails::class, async: true);
    }
});
```

## Registering Event Listeners

### In EventServiceProvider

Register listeners in your application's `EventServiceProvider`:

```php
use Cline\Sequencer\Events\{OperationStarted, OperationEnded, OperationsStarted, OperationsEnded};

protected $listen = [
    OperationsStarted::class => [
        LogOperationsBatchStarted::class,
        PrepareOperationResources::class,
    ],
    OperationStarted::class => [
        LogOperationStarted::class,
        StartOperationTimer::class,
    ],
    OperationEnded::class => [
        LogOperationCompleted::class,
        RecordOperationMetrics::class,
    ],
    OperationsEnded::class => [
        LogOperationsBatchCompleted::class,
        CleanupOperationResources::class,
    ],
];
```

### Using Closures

For simple use cases, register listeners with closures:

```php
use Cline\Sequencer\Events\OperationStarted;
use Illuminate\Support\Facades\Event;

// In AppServiceProvider boot method
Event::listen(OperationStarted::class, function ($event) {
    Log::info('Operation started: '.get_class($event->operation));
});
```

### Queueable Listeners

For expensive operations, queue your event listeners:

```php
use Cline\Sequencer\Events\OperationEnded;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOperationCompletedNotification implements ShouldQueue
{
    public function handle(OperationEnded $event): void
    {
        // This runs asynchronously
        Notification::send(
            User::admins(),
            new OperationCompletedNotification($event->operation)
        );
    }
}
```

## Best Practices

### 1. Keep Listeners Lightweight

Event listeners execute synchronously by default. Keep them fast or queue them:

```php
// ❌ Slow synchronous listener
Event::listen(OperationEnded::class, function ($event) {
    sleep(5); // Blocks operation completion
    ExternalApi::notify($event->operation);
});

// ✅ Queue expensive work
class NotifyExternalApi implements ShouldQueue
{
    public function handle(OperationEnded $event): void
    {
        ExternalApi::notify($event->operation);
    }
}
```

### 2. Handle Failures Gracefully

Don't let listener failures crash operations:

```php
Event::listen(OperationEnded::class, function ($event) {
    try {
        ExternalApi::notify($event->operation);
    } catch (\Throwable $e) {
        Log::error('Failed to notify external API', [
            'operation' => get_class($event->operation),
            'error' => $e->getMessage(),
        ]);
    }
});
```

### 3. Use Event Subscribers for Related Listeners

Group related listeners in a subscriber class:

```php
use Cline\Sequencer\Events\{OperationStarted, OperationEnded, OperationsStarted, OperationsEnded};
use Illuminate\Events\Dispatcher;

class OperationMetricsSubscriber
{
    public function handleBatchStarted(OperationsStarted $event): void
    {
        Metrics::increment('operations.batch.started');
    }

    public function handleOperationStarted(OperationStarted $event): void
    {
        Metrics::increment('operations.individual.started', [
            'type' => get_class($event->operation),
        ]);
    }

    public function handleOperationEnded(OperationEnded $event): void
    {
        Metrics::increment('operations.individual.completed', [
            'type' => get_class($event->operation),
        ]);
    }

    public function handleBatchEnded(OperationsEnded $event): void
    {
        Metrics::increment('operations.batch.completed');
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(OperationsStarted::class, [self::class, 'handleBatchStarted']);
        $events->listen(OperationStarted::class, [self::class, 'handleOperationStarted']);
        $events->listen(OperationEnded::class, [self::class, 'handleOperationEnded']);
        $events->listen(OperationsEnded::class, [self::class, 'handleBatchEnded']);
    }
}

// In EventServiceProvider
protected $subscribe = [
    OperationMetricsSubscriber::class,
];
```

## Testing with Events

Fake events in tests to assert they're dispatched:

```php
use Cline\Sequencer\Events\{OperationStarted, OperationEnded};
use Cline\Sequencer\Facades\Sequencer;
use Illuminate\Support\Facades\Event;

test('operation execution dispatches events', function () {
    Event::fake([OperationStarted::class, OperationEnded::class]);

    Sequencer::executeSync(ProcessData::class);

    Event::assertDispatched(OperationStarted::class, function ($event) {
        return $event->method === 'sync';
    });

    Event::assertDispatched(OperationEnded::class, function ($event) {
        return $event->operation instanceof ProcessData;
    });
});
```

## Next Steps

- **[Getting Started](getting-started.md)** - Installation and setup
- **[Basic Usage](basic-usage.md)** - Core operation interfaces
- **[Advanced Operations](advanced-operations.md)** - Retries, batching, lifecycle hooks
- **[Programmatic Usage](programmatic-usage.md)** - Facade API
