# Migrating from Other Operation Packages

When transitioning to Sequencer from another operation management package, you need to migrate your existing operation execution history to prevent duplicate execution. Sequencer provides migrators to import operation records from popular packages while preserving your execution history.

## Why Migration Matters

Operation management packages track which operations have been executed to prevent duplicate runs. When switching to Sequencer, you must migrate this execution history, or Sequencer will treat all your historical operations as "pending" and attempt to re-execute them—potentially causing data corruption, duplicate records, or other serious issues.

## Supported Packages

### TimoKoerber/laravel-one-time-operations

Sequencer includes a built-in migrator for [laravel-one-time-operations](https://github.com/TimoKoerber/laravel-one-time-operations), one of the most popular Laravel operation management packages.

#### Configuration

Configure the migrator in `config/sequencer.php`:

```php
'migrators' => [
    'one_time_operations' => [
        'table' => env('SEQUENCER_OTO_TABLE', 'one_time_operations'),
        'connection' => env('SEQUENCER_OTO_CONNECTION'),
    ],
],
```

#### Migration Process

1. **Before migrating**, ensure Sequencer's migrations have been run:

```bash
php artisan migrate
```

2. **Run the migration** using the Artisan command:

```bash
# Migrate from one_time_operations table
php artisan sequencer:migrate --driver=oto

# Preview migration without making changes
php artisan sequencer:migrate --driver=oto --dry-run

# Skip confirmation prompt
php artisan sequencer:migrate --driver=oto --force

# Use custom table name
php artisan sequencer:migrate --driver=oto --table=custom_operations

# Use different database connection
php artisan sequencer:migrate --driver=oto --connection=legacy_mysql
```

Alternatively, **run the migration programmatically**:

```php
use Cline\Sequencer\Migrators\OneTimeOperationsMigrator;

$migrator = new OneTimeOperationsMigrator(
    sourceTable: config('sequencer.migrators.one_time_operations.table'),
    sourceConnection: config('sequencer.migrators.one_time_operations.connection'),
);

$migrator->migrate();

// Review migration results
$stats = $migrator->getStatistics();
echo "Migrated {$stats['operations']} operations\n";
echo "Skipped {$stats['skipped']} duplicate operations\n";

if (!empty($stats['errors'])) {
    foreach ($stats['errors'] as $error) {
        echo "Error: {$error}\n";
    }
}
```

3. **Verify the migration** by checking your operations table:

```php
use Illuminate\Support\Facades\DB;

$count = DB::table('operations')->count();
echo "Total operations in Sequencer: {$count}\n";
```

#### What Gets Migrated

The migrator imports:

- **Operation names**: Preserved exactly as stored in `one_time_operations.name`
- **Execution type**: Maps `dispatched` enum to Sequencer's execution methods (sync/async)
- **Timestamps**: Uses `processed_at` for both `executed_at` and `completed_at`
- **State**: All migrated operations are marked as `completed`

#### What Doesn't Get Migrated

- **Pending operations**: Only operations with `processed_at` timestamps are migrated
- **Failed operations**: The source package doesn't track failures, so these won't exist
- **Error details**: No error history exists in the source schema

#### Idempotent Migrations

The migrator is idempotent—you can safely run it multiple times. Operations that already exist in Sequencer are skipped:

```php
$migrator = new OneTimeOperationsMigrator();
$migrator->migrate();

$stats = $migrator->getStatistics();
// $stats['skipped'] will show how many operations were already present
```

This is useful for:
- Testing migrations in staging before production
- Re-running migrations if initial attempts had partial failures
- Incremental migrations where new operations were added to the source system

## Custom Table Names

If you're using custom table names, configure them appropriately:

```php
// .env
SEQUENCER_OTO_TABLE=custom_operations_table
SEQUENCER_OTO_CONNECTION=mysql_legacy

// Or pass directly to migrator
$migrator = new OneTimeOperationsMigrator(
    sourceTable: 'custom_operations_table',
    sourceConnection: 'mysql_legacy',
);
```

## Cross-Connection Migrations

Migrate from a different database connection:

```php
$migrator = new OneTimeOperationsMigrator(
    sourceTable: 'one_time_operations',
    sourceConnection: 'legacy_mysql',    // Source connection
    targetConnection: 'pgsql_primary',   // Target connection
);

$migrator->migrate();
```

This is useful when:
- Migrating from MySQL to PostgreSQL
- Consolidating multiple databases
- Moving from an old server to a new infrastructure

## Troubleshooting

### Migration Errors

Check the statistics for detailed error messages:

```php
$migrator = new OneTimeOperationsMigrator();
$migrator->migrate();

$stats = $migrator->getStatistics();
if (!empty($stats['errors'])) {
    Log::error('Migration errors', $stats['errors']);
}
```

Common errors:

**Missing required fields**: The source table schema doesn't match expectations.
```
Failed to migrate operation: Operation record missing required "name" field
```
*Solution*: Verify the source table has `name`, `dispatched`, and `processed_at` columns.

**Table doesn't exist**:
```
Migration failed: SQLSTATE[42S02]: Base table or view not found
```
*Solution*: Check that the source table name is correct and exists in the specified connection.

### Partial Migrations

If a migration fails partway through, the migrator tracks which operations succeeded:

```php
$stats = $migrator->getStatistics();
echo "Successfully migrated: {$stats['operations']}\n";
echo "Errors: " . count($stats['errors']) . "\n";
```

Since the migrator is idempotent, you can:
1. Fix the underlying issue
2. Re-run the migration
3. Already-migrated operations will be skipped

### Validation

After migration, verify the counts match:

```php
use Illuminate\Support\Facades\DB;

$sourceCount = DB::connection('legacy')
    ->table('one_time_operations')
    ->whereNotNull('processed_at')
    ->count();

$targetCount = DB::table('operations')->count();

if ($sourceCount === $targetCount) {
    echo "✓ Migration successful: {$targetCount} operations migrated\n";
} else {
    echo "⚠ Count mismatch: {$sourceCount} source / {$targetCount} target\n";
}
```

## Best Practices

### Test First

Always test migrations in a non-production environment:

```php
// In staging/development
$migrator = new OneTimeOperationsMigrator();
$migrator->migrate();

$stats = $migrator->getStatistics();
// Review statistics and verify data integrity
```

### Backup First

Create database backups before running migrations:

```bash
# PostgreSQL
pg_dump -U username -d database > backup_before_migration.sql

# MySQL
mysqldump -u username -p database > backup_before_migration.sql
```

### Transaction Wrapping

Wrap migrations in transactions for easy rollback on failure:

```php
DB::transaction(function () {
    $migrator = new OneTimeOperationsMigrator();
    $migrator->migrate();

    $stats = $migrator->getStatistics();
    if (!empty($stats['errors'])) {
        throw new \RuntimeException('Migration had errors');
    }
});
```

### Monitor Performance

For large datasets (1000+ operations), monitor migration performance:

```php
$start = microtime(true);
$migrator = new OneTimeOperationsMigrator();
$migrator->migrate();
$duration = microtime(true) - $start;

$stats = $migrator->getStatistics();
echo "Migrated {$stats['operations']} operations in {$duration} seconds\n";
```

## Migration Checklist

Before deploying to production:

- [ ] Test migration in staging environment
- [ ] Backup production database
- [ ] Verify source table structure matches expectations
- [ ] Confirm operation counts in source system
- [ ] Review migration statistics for errors
- [ ] Validate operation records in Sequencer
- [ ] Test that Sequencer skips already-executed operations
- [ ] Remove or archive old package after confirming success

## Post-Migration

After successful migration:

1. **Remove the old package** from your composer dependencies:
```bash
composer remove timokoerber/laravel-one-time-operations
```

2. **Update your operations** to use Sequencer's interface:
```php
// Old laravel-one-time-operations style
namespace App\Operations;

use TimoKoerber\LaravelOneTimeOperations\OneTimeOperation;

class MigrateUsers extends OneTimeOperation
{
    public function process(): void
    {
        // Operation logic
    }
}

// New Sequencer style
namespace App\Operations;

use Cline\Sequencer\Contracts\Operation;

return new class implements Operation {
    public function handle(): void
    {
        // Operation logic
    }
};
```

3. **Clean up configuration** by removing old package config files.

4. **Update documentation** to reflect the new Sequencer-based workflow.

## Getting Help

If you encounter issues during migration:

1. Review the error messages in migration statistics
2. Check the [GitHub Issues](https://github.com/cline-sh/sequencer/issues) for similar problems
3. Enable query logging to debug database connection issues
4. Consult the Sequencer documentation for advanced configuration options
