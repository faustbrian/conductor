<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Migrators\OneTimeOperationsMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

use function config;
use function count;
use function is_string;
use function sprintf;

/**
 * Artisan command to migrate operation history from other packages to Sequencer.
 *
 * This command migrates operation execution records from various operation management
 * packages to Sequencer's operations table, preserving execution history to prevent
 * duplicate execution when transitioning to Sequencer.
 *
 * ```bash
 * # Migrate from one_time_operations table
 * php artisan sequencer:migrate --driver=oto
 *
 * # Migrate from custom table
 * php artisan sequencer:migrate --driver=oto --table=custom_operations
 *
 * # Migrate from different database connection
 * php artisan sequencer:migrate --driver=oto --connection=legacy_mysql
 *
 * # Dry run to preview migration
 * php artisan sequencer:migrate --driver=oto --dry-run
 *
 * # Force migration without confirmation
 * php artisan sequencer:migrate --driver=oto --force
 * ```
 *
 * @see OneTimeOperationsMigrator
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sequencer:migrate
                            {--driver= : The migration driver (oto)}
                            {--table= : The source table name}
                            {--connection= : The source database connection}
                            {--dry-run : Preview migration without making changes}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate operation history from other packages to Sequencer';

    /**
     * Execute the console command.
     *
     * Validates the driver is specified and supported, validates the source table exists,
     * shows a preview of operations to migrate, prompts for confirmation (unless --force),
     * then executes the migration and displays detailed statistics including success count,
     * skipped duplicates, and any errors encountered.
     *
     * @return int Command exit code (self::SUCCESS or self::FAILURE)
     */
    public function handle(): int
    {
        $driver = $this->option('driver');

        if ($driver === null) {
            $this->error('Migration driver is required.');
            $this->newLine();
            $this->comment('Available drivers:');
            $this->comment('  - oto: TimoKoerber/laravel-one-time-operations');
            $this->newLine();
            $this->comment('Example: php artisan sequencer:migrate --driver=oto');

            return self::FAILURE;
        }

        // Ensure driver is a string
        if (!is_string($driver)) {
            $this->error('Migration driver must be a string.');

            return self::FAILURE;
        }

        if ($driver !== 'oto') {
            $this->error(sprintf('Unsupported migration driver: %s', $driver));
            $this->newLine();
            $this->comment('Available drivers:');
            $this->comment('  - oto: TimoKoerber/laravel-one-time-operations');

            return self::FAILURE;
        }

        return $this->migrateFromOneTimeOperations();
    }

    /**
     * Migrate from TimoKoerber/laravel-one-time-operations package.
     *
     * @return int Command exit code (self::SUCCESS or self::FAILURE)
     */
    private function migrateFromOneTimeOperations(): int
    {
        $sourceTableRaw = $this->option('table') ?? config('sequencer.migrators.one_time_operations.table', 'one_time_operations');
        $sourceConnectionRaw = $this->option('connection') ?? config('sequencer.migrators.one_time_operations.connection');

        // Ensure types are correct for PHPStan
        $sourceTable = is_string($sourceTableRaw) ? $sourceTableRaw : 'one_time_operations';
        $sourceConnection = $sourceConnectionRaw !== null && is_string($sourceConnectionRaw) ? $sourceConnectionRaw : null;

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info('Sequencer Migration from laravel-one-time-operations');
        $this->newLine();

        // Validate source table exists
        try {
            $query = $sourceConnection === null
                ? DB::table($sourceTable)
                : DB::connection($sourceConnection)->table($sourceTable);

            $totalOperations = $query->whereNotNull('processed_at')->count();
        } catch (Throwable $throwable) {
            $this->error(sprintf('Unable to access source table "%s": %s', $sourceTable, $throwable->getMessage()));
            $this->newLine();
            $this->comment('Verify that:');
            $this->comment('  - The table name is correct');
            $this->comment('  - The database connection is configured');
            $this->comment('  - The table has been created by laravel-one-time-operations');

            return self::FAILURE;
        }

        if ($totalOperations === 0) {
            $this->warn('No completed operations found in source table.');
            $this->comment('Only operations with processed_at timestamps are migrated.');

            return self::SUCCESS;
        }

        // Check for already-migrated operations
        $existingCount = 0;

        try {
            $query = $sourceConnection === null
                ? DB::table($sourceTable)
                : DB::connection($sourceConnection)->table($sourceTable);

            $sourceOperations = $query
                ->whereNotNull('processed_at')
                ->pluck('name')
                ->toArray();

            $operationsTableRaw = config('sequencer.table_names.operations', 'operations');
            $operationsTable = is_string($operationsTableRaw) ? $operationsTableRaw : 'operations';
            $existingCount = DB::table($operationsTable)
                ->whereIn('name', $sourceOperations)
                ->count();
        } catch (Throwable) {
            // Ignore errors checking for existing operations
        }

        $newOperations = $totalOperations - $existingCount;

        // Display migration preview
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total operations in source', $totalOperations],
                ['Already migrated (will be skipped)', $existingCount],
                ['New operations to migrate', $newOperations],
            ],
        );

        if ($dryRun) {
            $this->newLine();
            $this->info('✓ Dry run completed - no changes made');

            return self::SUCCESS;
        }

        // Confirm migration
        if (!$force && $newOperations > 0) {
            $this->newLine();

            if (!$this->confirm(sprintf('Migrate %d operation(s) to Sequencer?', $newOperations), true)) {
                $this->comment('Migration cancelled.');

                return self::SUCCESS;
            }
        }

        // Execute migration
        $this->newLine();
        $this->info('Starting migration...');

        $migrator = new OneTimeOperationsMigrator(
            sourceTable: $sourceTable,
            sourceConnection: $sourceConnection,
        );

        try {
            $migrator->migrate();
        } catch (Throwable $throwable) {
            $this->newLine();
            $this->error('Migration failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        // Display results
        $stats = $migrator->getStatistics();
        $this->newLine();
        $this->info('Migration completed!');
        $this->newLine();

        $this->table(
            ['Result', 'Count'],
            [
                ['Operations migrated', $stats['operations']],
                ['Operations skipped (duplicates)', $stats['skipped']],
                ['Errors', count($stats['errors'])],
            ],
        );

        // Display errors if any
        if (!empty($stats['errors'])) {
            $this->newLine();
            $this->warn('Errors encountered during migration:');

            foreach ($stats['errors'] as $error) {
                $this->line('  • '.$error);
            }
        }

        // Final success/warning message
        $this->newLine();

        if (empty($stats['errors'])) {
            $this->info(sprintf('✓ Successfully migrated %d operation(s) to Sequencer', $stats['operations']));
        } else {
            $this->warn(sprintf('⚠ Migrated %d operation(s) with %d error(s)', $stats['operations'], count($stats['errors'])));
            $this->comment('Review errors above and re-run migration if needed (already-migrated operations will be skipped)');
        }

        return self::SUCCESS;
    }
}
