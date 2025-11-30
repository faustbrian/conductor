# Getting Started

Welcome to Sequencer, a powerful Laravel package that orchestrates sequential execution of migrations and operations. This guide will help you install, configure, and create your first operation.

## Installation

Install Sequencer via Composer:

```bash
composer require cline/sequencer
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=sequencer-config
```

This creates `config/sequencer.php` with the following structure:

```php
return [
    // Primary key type for database tables
    'primary_key_type' => env('SEQUENCER_PRIMARY_KEY_TYPE', 'id'),

    // Polymorphic relationship type
    'morph_type' => env('SEQUENCER_MORPH_TYPE', 'morph'),

    'execution' => [
        // Operation discovery paths
        'discovery_paths' => [
            database_path('operations'),
        ],

        // Auto-wrap operations in transactions
        'auto_transaction' => env('SEQUENCER_AUTO_TRANSACTION', true),

        // Atomic locking for multi-server deployments
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

### Primary Key Configuration

Sequencer supports three primary key types:

- **`id`** (default) - Auto-incrementing integers
- **`ulid`** - Universally Unique Lexicographically Sortable Identifier
- **`uuid`** - Universally Unique Identifier

Set your preferred type in `.env`:

```env
SEQUENCER_PRIMARY_KEY_TYPE=ulid
```

### Morph Type Configuration

Configure polymorphic relationship types:

- **`morph`** (default) - Standard Laravel morphs
- **`uuidMorph`** - UUID-based polymorphic relationships
- **`ulidMorph`** - ULID-based polymorphic relationships
- **`numericMorph`** - Numeric polymorphic relationships

## Database Setup

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=sequencer-migrations
php artisan migrate
```

This creates two tables:

### operations

Tracks all executed operations with their status and timing:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `name` | string | Operation class name |
| `type` | string | Execution type (sync/async/fake) |
| `executed_by_type` | string | Polymorphic executor type |
| `executed_by_id` | bigint/ulid/uuid | Polymorphic executor ID |
| `executed_at` | timestamp | When operation started |
| `completed_at` | timestamp | When operation finished |
| `failed_at` | timestamp | When operation failed |
| `rolled_back_at` | timestamp | When operation was rolled back |

### operation_errors

Stores detailed error information for failed operations:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `operation_id` | bigint/ulid/uuid | Foreign key to operations |
| `exception` | string | Exception class name |
| `message` | text | Error message |
| `trace` | text | Full stack trace |
| `context` | json | Error context (file, line, code) |
| `created_at` | timestamp | When error was recorded |

## Your First Operation

Let's create a simple operation to seed an admin user.

### 1. Create the Operation File

Operations follow the same naming convention as migrations:

```bash
# Manual creation
touch database/operations/2024_01_15_120000_seed_admin_user.php
```

### 2. Define the Operation Class

```php
<?php

use Cline\Sequencer\Contracts\Operation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

return new class implements Operation
{
    public function handle(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);
    }
};
```

### 3. Execute the Operation

Run Sequencer to execute all pending migrations and operations:

```bash
php artisan sequencer:process
```

Output:

```
Running with sequential orchestration...
All migrations and operations processed successfully.
```

## Sequential Execution

Sequencer executes migrations and operations in chronological order based on timestamps:

```
database/migrations/2024_01_01_000000_create_users_table.php
database/operations/2024_01_02_000000_seed_admin_user.php
database/migrations/2024_01_03_000000_add_status_to_users.php
database/operations/2024_01_04_000000_update_user_statuses.php
```

This solves the traditional problem where all migrations run first, then all operations, causing dependency issues.

## Dry-Run Mode

Preview what will execute without actually running anything:

```bash
php artisan sequencer:process --dry-run
```

Output:

```
Dry-run mode: Previewing execution order...

┌───────────┬─────────────────────┬────────────────────────────┐
│ Type      │ Timestamp           │ Name                       │
├───────────┼─────────────────────┼────────────────────────────┤
│ Migration │ 2024_01_01_000000   │ create_users_table         │
│ Operation │ 2024_01_02_000000   │ SeedAdminUser              │
│ Migration │ 2024_01_03_000000   │ add_status_to_users        │
│ Operation │ 2024_01_04_000000   │ UpdateUserStatuses         │
└───────────┴─────────────────────┴────────────────────────────┘

Found 4 pending task(s).
```

## Multi-Server Deployments

Use the `--isolate` flag to prevent concurrent execution across multiple servers:

```bash
php artisan sequencer:process --isolate
```

This uses an atomic lock (via Redis/Database) to ensure only one server processes operations at a time.

## Resume After Failure

If execution fails midway, resume from a specific timestamp:

```bash
php artisan sequencer:process --from=2024_01_03_000000
```

This skips all tasks before the specified timestamp and resumes execution.

## Next Steps

Now that you've created your first operation, explore:

- **[Basic Usage](basic-usage.md)** - Learn about operation interfaces and patterns
- **[Rollback Support](rollback-support.md)** - Handle failures gracefully
- **[Dependencies](dependencies.md)** - Declare explicit operation ordering
- **[Conditional Execution](conditional-execution.md)** - Skip operations based on runtime conditions
- **[Advanced Usage](advanced-usage.md)** - Transactions, async operations, and observability
