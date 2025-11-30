# Orchestration Strategies

Sequencer provides multiple orchestration strategies for executing operations with different execution patterns: sequential, parallel, transactional, dependency-based, and scheduled.

## Available Orchestrators

### SequentialOrchestrator (Default)

Executes migrations and operations in chronological order based on timestamps. This is the default orchestrator and maintains backward compatibility.

**Use Cases:**
- Standard deployments where operations must run in order
- Simple migration sequences
- When you need predictable, one-at-a-time execution

**Configuration:**
```php
// config/sequencer.php
'orchestrator' => \Cline\Sequencer\SequentialOrchestrator::class,
```

**Usage:**
```bash
php artisan sequencer:process
```

```php
use Cline\Sequencer\Facades\Sequencer;

Sequencer::executeAll();
```

---

### BatchOrchestrator

Executes all operations in parallel using Laravel's batch system. Migrations still run sequentially, but operations dispatch as a single batch for parallel processing.

**Use Cases:**
- Operations that are independent and can run simultaneously
- Large deployments where parallel execution speeds up completion
- When operations don't depend on each other

**Configuration:**
```php
// config/sequencer.php
'orchestrator' => \Cline\Sequencer\Orchestrators\BatchOrchestrator::class,
```

**Runtime Override:**
```php
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\Orchestrators\BatchOrchestrator;

Sequencer::using(BatchOrchestrator::class)->executeAll();
```

**Example:**
```php
// database/operations/2024_01_15_120000_process_orders.php
return new class implements Operation {
    public function handle(): void {
        // Process orders independently
    }
};

// database/operations/2024_01_15_120001_update_inventory.php
return new class implements Operation {
    public function handle(): void {
        // Update inventory independently
    }
};

// Both run in parallel
```

---

### TransactionalBatchOrchestrator

All-or-nothing batch execution. If **any** operation fails, all completed operations are automatically rolled back in reverse order.

**Use Cases:**
- Critical data migrations where partial completion is unacceptable
- Financial operations requiring atomicity
- Operations where consistency across all changes is mandatory

**Configuration:**
```php
// config/sequencer.php
'orchestrator' => \Cline\Sequencer\Orchestrators\TransactionalBatchOrchestrator::class,
```

**Runtime Override:**
```php
use Cline\Sequencer\Orchestrators\TransactionalBatchOrchestrator;

Sequencer::using(TransactionalBatchOrchestrator::class)->executeAll();
```

**Example:**
```php
// database/operations/2024_01_15_120000_migrate_user_data.php
return new class implements Operation, Rollbackable {
    public function handle(): void {
        // Migrate users to new schema
        User::query()->update(['schema_version' => 2]);
    }

    public function rollback(): void {
        // Revert to old schema
        User::query()->update(['schema_version' => 1]);
    }
};

// database/operations/2024_01_15_120001_migrate_orders.php
return new class implements Operation, Rollbackable {
    public function handle(): void {
        // Migrate orders
        Order::query()->update(['schema_version' => 2]);
    }

    public function rollback(): void {
        Order::query()->update(['schema_version' => 1]);
    }
};

// If orders migration fails, users migration is automatically rolled back
```

**Important:**
- Only operations implementing `Rollbackable` will be rolled back
- Rollback happens in reverse chronological order
- Non-rollbackable operations cannot be undone

---

### AllowedToFailBatchOrchestrator

Batch execution where individual operations can fail without affecting others. Operations implementing `AllowedToFail` can fail without causing batch failure.

**Use Cases:**
- Non-critical operations like sending emails, clearing caches
- Operations with eventual consistency requirements
- When some failures are acceptable and can be retried later

**Configuration:**
```php
// config/sequencer.php
'orchestrator' => \Cline\Sequencer\Orchestrators\AllowedToFailBatchOrchestrator::class,
```

**Runtime Override:**
```php
use Cline\Sequencer\Orchestrators\AllowedToFailBatchOrchestrator;

Sequencer::using(AllowedToFailBatchOrchestrator::class)->executeAll();
```

**Example:**
```php
// database/operations/2024_01_15_120000_migrate_critical_data.php
return new class implements Operation {
    public function handle(): void {
        // CRITICAL: Must succeed or entire batch fails
        CriticalData::migrate();
    }
};

// database/operations/2024_01_15_120001_send_welcome_emails.php
use Cline\Sequencer\Contracts\AllowedToFail;

return new class implements Operation, AllowedToFail {
    public function handle(): void {
        // NON-CRITICAL: Failure won't block deployment
        User::query()->each(fn($user) => Mail::to($user)->send(new WelcomeEmail));
    }
};

// database/operations/2024_01_15_120002_warm_cache.php
use Cline\Sequencer\Contracts\AllowedToFail;

return new class implements Operation, AllowedToFail {
    public function handle(): void {
        // NON-CRITICAL: Can regenerate cache later
        Cache::warmUp();
    }
};

// If email or cache operations fail, batch continues
// If critical data migration fails, entire batch fails
```

**Behavior:**
- Operations **without** `AllowedToFail`: Failure causes batch to fail
- Operations **with** `AllowedToFail`: Failure is logged but batch continues
- Failed AllowedToFail operations are logged for manual review

---

### DependencyGraphOrchestrator

Executes operations in topologically-sorted "waves" based on declared dependencies. Operations within the same wave run in parallel, but waves execute sequentially.

**Use Cases:**
- Complex migration sequences with interdependencies
- Fan-out/fan-in patterns
- Operations with explicit ordering requirements beyond timestamps

**Configuration:**
```php
// config/sequencer.php
'orchestrator' => \Cline\Sequencer\Orchestrators\DependencyGraphOrchestrator::class,
```

**Runtime Override:**
```php
use Cline\Sequencer\Orchestrators\DependencyGraphOrchestrator;

Sequencer::using(DependencyGraphOrchestrator::class)->executeAll();
```

**Example:**
```php
// database/operations/2024_01_15_120000_create_users_table.php
return new class implements Operation {
    public function handle(): void {
        Schema::create('users', function($table) {
            $table->id();
            $table->string('name');
        });
    }
};

// database/operations/2024_01_15_120001_create_orders_table.php
use Cline\Sequencer\Contracts\HasDependencies;

return new class implements Operation, HasDependencies {
    public function dependsOn(): array {
        return ['2024_01_15_120000_create_users_table'];
    }

    public function handle(): void {
        Schema::create('orders', function($table) {
            $table->id();
            $table->foreignId('user_id');
        });
    }
};

// database/operations/2024_01_15_120002_create_products_table.php
use Cline\Sequencer\Contracts\HasDependencies;

return new class implements Operation, HasDependencies {
    public function dependsOn(): array {
        return ['2024_01_15_120000_create_users_table'];
    }

    public function handle(): void {
        Schema::create('products', function($table) {
            $table->id();
            $table->foreignId('created_by');
        });
    }
};

// Execution waves:
// Wave 1: create_users_table (no dependencies)
// Wave 2: create_orders_table and create_products_table (parallel)
```

**Features:**
- Automatic topological sorting
- Detects circular dependencies
- Parallel execution within waves
- Supports both operation and migration dependencies

---

### ScheduledOrchestrator

Delays execution of operations until their scheduled time. Operations implementing `Scheduled` are queued with appropriate delays.

**Use Cases:**
- Maintenance windows during low-traffic hours
- Coordinated multi-region deployments
- Time-sensitive migrations

**Configuration:**
```php
// config/sequencer.php
'orchestrator' => \Cline\Sequencer\Orchestrators\ScheduledOrchestrator::class,
```

**Runtime Override:**
```php
use Cline\Sequencer\Orchestrators\ScheduledOrchestrator;

Sequencer::using(ScheduledOrchestrator::class)->executeAll();
```

**Example:**
```php
// database/operations/2024_01_15_120000_scheduled_maintenance.php
use Cline\Sequencer\Contracts\Scheduled;

return new class implements Operation, Scheduled {
    public function executeAt(): \DateTimeInterface {
        // Run at 2am local time
        return now()->setTime(2, 0);
    }

    public function handle(): void {
        // Perform heavy maintenance during low-traffic period
        DB::statement('VACUUM ANALYZE');
    }
};

// Non-scheduled operation - runs immediately
// database/operations/2024_01_15_120001_urgent_fix.php
return new class implements Operation {
    public function handle(): void {
        // Runs immediately
        BugFix::apply();
    }
};
```

**Scheduled Command:**
```bash
# Add to app/Console/Kernel.php
$schedule->command('sequencer:scheduled')->everyMinute();

# Or run manually
php artisan sequencer:scheduled
```

**Behavior:**
- Operations with `Scheduled`: Queued with delay until `executeAt()` time
- Operations without `Scheduled`: Execute immediately
- If `executeAt()` is in the past: Executes immediately
- Scheduled command checks every minute for due operations

---

## Fluent API

All orchestrators support both configuration-based and runtime selection:

### Configuration-Based (Global Default)

```php
// config/sequencer.php
return [
    'orchestrator' => \Cline\Sequencer\Orchestrators\BatchOrchestrator::class,
];
```

### Runtime Selection (Per-Execution)

```php
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\Orchestrators\{
    BatchOrchestrator,
    TransactionalBatchOrchestrator,
    AllowedToFailBatchOrchestrator,
    DependencyGraphOrchestrator,
    ScheduledOrchestrator,
};

// Use specific orchestrator for this execution
Sequencer::using(BatchOrchestrator::class)->executeAll();

// With options
Sequencer::using(TransactionalBatchOrchestrator::class)
    ->executeAll(isolate: true, from: '2024_01_15_120000');

// Preview with custom orchestrator
Sequencer::using(DependencyGraphOrchestrator::class)->preview();
```

---

## Combining Strategies

You can combine contracts for sophisticated orchestration:

```php
// Scheduled + Rollbackable + AllowedToFail
use Cline\Sequencer\Contracts\{Scheduled, Rollbackable, AllowedToFail};

return new class implements Operation, Scheduled, Rollbackable, AllowedToFail {
    public function executeAt(): \DateTimeInterface {
        return now()->setTime(3, 0); // 3am
    }

    public function handle(): void {
        // Cache warmup
    }

    public function rollback(): void {
        // Clear cache
    }
};
```

---

## Orchestrator Comparison

| Orchestrator | Execution | Rollback | Use Case |
|-------------|-----------|----------|----------|
| **Sequential** | One-at-a-time | Manual | Default, predictable order |
| **Batch** | Parallel | Manual | Independent operations |
| **TransactionalBatch** | Parallel | Automatic on failure | All-or-nothing atomicity |
| **AllowedToFailBatch** | Parallel | No rollback | Non-critical ops can fail |
| **DependencyGraph** | Waves (parallel) | Optional | Complex dependencies |
| **Scheduled** | Time-based | Per-operation | Maintenance windows |

---

## Best Practices

### 1. Choose the Right Orchestrator

- **Default to Sequential** for standard deployments
- **Use Batch** when operations are truly independent
- **Use TransactionalBatch** for critical data migrations
- **Use AllowedToFailBatch** for mixed critical/non-critical ops
- **Use DependencyGraph** when operations have complex dependencies
- **Use Scheduled** for time-sensitive operations

### 2. Implement Rollback for Transactional Operations

```php
use Cline\Sequencer\Contracts\Rollbackable;

return new class implements Operation, Rollbackable {
    public function handle(): void {
        // Forward migration
    }

    public function rollback(): void {
        // Reverse migration - MUST undo handle()
    }
};
```

### 3. Mark Non-Critical Operations

```php
use Cline\Sequencer\Contracts\AllowedToFail;

return new class implements Operation, AllowedToFail {
    public function handle(): void {
        // Failure acceptable - won't block deployment
    }
};
```

### 4. Declare Dependencies Explicitly

```php
use Cline\Sequencer\Contracts\HasDependencies;

return new class implements Operation, HasDependencies {
    public function dependsOn(): array {
        return [
            '2024_01_15_120000_create_users_table',
            CreateProductsTable::class,
        ];
    }

    public function handle(): void {
        // Runs after dependencies
    }
};
```

### 5. Test Orchestration Locally

```bash
# Preview execution order
php artisan sequencer:process --dry-run

# Preview with custom orchestrator
php artisan sequencer:process --dry-run # then set SEQUENCER_ORCHESTRATOR env
```

---

## Advanced Usage

### Custom Orchestrator

Create your own orchestrator by implementing the `Orchestrator` contract:

```php
namespace App\Orchestrators;

use Cline\Sequencer\Contracts\Orchestrator;

class CustomOrchestrator implements Orchestrator {
    public function process(
        bool $isolate = false,
        bool $dryRun = false,
        ?string $from = null,
        bool $repeat = false
    ): ?array {
        // Your custom execution logic
    }
}
```

Use it:
```php
Sequencer::using(CustomOrchestrator::class)->executeAll();
```

---

## Troubleshooting

### Operations Execute Out of Order

**Problem:** Dependencies aren't respected

**Solution:** Use `DependencyGraphOrchestrator` and implement `HasDependencies`

### Partial Batch Completion

**Problem:** Some operations succeeded, others failed

**Solution:** Use `TransactionalBatchOrchestrator` for automatic rollback

### Non-Critical Failures Block Deployment

**Problem:** Email sending failures prevent deployment

**Solution:** Implement `AllowedToFail` for non-critical operations

### Scheduled Operations Not Running

**Problem:** `sequencer:scheduled` not configured

**Solution:** Add to Laravel scheduler:
```php
$schedule->command('sequencer:scheduled')->everyMinute();
```
