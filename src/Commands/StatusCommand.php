<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Support\MigrationDiscovery;
use Cline\Sequencer\Support\OperationDiscovery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migrator;

use function array_map;
use function count;
use function sprintf;

/**
 * Artisan command to display status of migrations and operations.
 *
 * Provides comprehensive status reporting for the Sequencer system, showing pending
 * migrations and operations, successfully completed operations, and failed operations
 * with detailed error information. Supports filtering to show specific status categories
 * for focused troubleshooting and monitoring.
 *
 * ```bash
 * # Show all status information (pending, completed, failed)
 * php artisan sequencer:status
 *
 * # Show only pending migrations and operations
 * php artisan sequencer:status --pending
 *
 * # Show only completed operations
 * php artisan sequencer:status --completed
 *
 * # Show only failed operations with error details
 * php artisan sequencer:status --failed
 *
 * # Show failed operations with full stack traces
 * php artisan sequencer:status --failed -v
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Supports three mutually exclusive filter flags for displaying specific status categories:
     * - pending: Shows only migrations and operations that have not yet been executed
     * - failed: Shows only operations that encountered errors during execution with error details
     * - completed: Shows only operations that executed successfully with completion timestamps
     *
     * When no flags are provided, displays all status categories for comprehensive system overview.
     *
     * @var string
     */
    protected $signature = 'sequencer:status
                            {--pending : Show only pending migrations and operations}
                            {--failed : Show only failed operations}
                            {--completed : Show only completed operations}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Display status of migrations and operations';

    /**
     * Execute the console command to display status information.
     *
     * Routes to the appropriate display methods based on command options. When no specific
     * filter is provided, displays all status categories (pending, completed, and failed).
     * Otherwise, shows only the requested status category for focused reporting.
     *
     * @param  MigrationDiscovery $migrationDiscovery service that scans filesystem paths to discover
     *                                                pending migrations that have not yet been executed,
     *                                                comparing available migration files against the
     *                                                migrations database table
     * @param  OperationDiscovery $operationDiscovery service that scans filesystem paths to discover
     *                                                pending operations, comparing available operation
     *                                                files against the operations database table to
     *                                                determine execution status
     * @param  Migrator           $migrator           Laravel's migration system, currently injected for dependency
     *                                                injection but not actively used. Reserved for future functionality
     *                                                that may require direct migration system interaction.
     * @return int                Command exit code: always returns self::SUCCESS (0) as status display
     *                            cannot fail (database query errors are handled internally)
     */
    public function handle(
        MigrationDiscovery $migrationDiscovery,
        OperationDiscovery $operationDiscovery,
        Migrator $migrator,
    ): int {
        $showPending = (bool) $this->option('pending');
        $showFailed = (bool) $this->option('failed');
        $showCompleted = (bool) $this->option('completed');

        // If no specific filter is set, show all
        $showAll = !$showPending && !$showFailed && !$showCompleted;

        if ($showAll || $showPending) {
            $this->displayPendingMigrations($migrationDiscovery);
            $this->newLine();
            $this->displayPendingOperations($operationDiscovery);
            $this->newLine();
        }

        if ($showAll || $showCompleted) {
            $this->displayCompletedOperations();
            $this->newLine();
        }

        if ($showAll || $showFailed) {
            $this->displayFailedOperations();
        }

        return self::SUCCESS;
    }

    /**
     * Display pending migrations that haven't been executed.
     *
     * Queries the discovery service for migrations that exist in the filesystem but have not
     * been recorded in the migrations database table. Displays results in a table showing
     * timestamp and descriptive name for each pending migration.
     *
     * @param MigrationDiscovery $discovery service that scans migration directories and compares
     *                                      found files against the migrations table to identify
     *                                      pending migrations that need execution
     */
    private function displayPendingMigrations(MigrationDiscovery $discovery): void
    {
        $pending = $discovery->getPending();

        $this->components->info('Pending Migrations');

        if ($pending === []) {
            $this->components->warn('No pending migrations found.');

            return;
        }

        $this->table(
            ['Timestamp', 'Name'],
            array_map(fn (array $migration): array => [
                $migration['timestamp'],
                $migration['name'],
            ], $pending),
        );

        $this->components->info(sprintf('Total: %d pending migration(s)', count($pending)));
    }

    /**
     * Display pending operations that haven't been executed.
     *
     * Queries the discovery service for operations that exist in the filesystem but have not
     * been recorded in the operations database table. Displays results in a table showing
     * timestamp and descriptive name for each pending operation.
     *
     * @param OperationDiscovery $discovery service that scans operation directories and compares
     *                                      found files against the operations table to identify
     *                                      pending operations that need execution
     */
    private function displayPendingOperations(OperationDiscovery $discovery): void
    {
        $pending = $discovery->getPending();

        $this->components->info('Pending Operations');

        if ($pending === []) {
            $this->components->warn('No pending operations found.');

            return;
        }

        $this->table(
            ['Timestamp', 'Name'],
            array_map(fn (array $operation): array => [
                $operation['timestamp'],
                $operation['name'],
            ], $pending),
        );

        $this->components->info(sprintf('Total: %d pending operation(s)', count($pending)));
    }

    /**
     * Display completed operations with execution and completion timestamps.
     *
     * Queries the operations database table for all operations that completed successfully
     * (have completed_at timestamp but no failed_at timestamp). Results are ordered by
     * completion time in descending order (most recent first) and displayed in a table
     * showing operation name, type, execution time, and completion time.
     */
    private function displayCompletedOperations(): void
    {
        /** @var Collection<int, Operation> $completed */
        $completed = Operation::query()
            ->whereNotNull('completed_at')
            ->whereNull('failed_at')
            ->latest('completed_at')
            ->get();

        $this->components->info('Completed Operations');

        if ($completed->isEmpty()) {
            $this->components->warn('No completed operations found.');

            return;
        }

        $this->table(
            ['Name', 'Type', 'Executed At', 'Completed At'],
            $completed->map(fn (Operation $operation): array => [
                $operation->name,
                $operation->type,
                $operation->executed_at->format('Y-m-d H:i:s'),
                $operation->completed_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ])->all(),
        );

        $this->components->info(sprintf('Total: %d completed operation(s)', $completed->count()));
    }

    /**
     * Display failed operations with comprehensive error details and stack traces.
     *
     * Queries the operations database table for all operations that have failed (have failed_at
     * timestamp), eagerly loading their associated error records. For each failed operation,
     * displays the operation name and failure time, followed by a table of all error records
     * showing exception class, error message, and occurrence timestamp. When verbose mode is
     * enabled (-v flag), also displays full stack traces for each error.
     */
    private function displayFailedOperations(): void
    {
        /** @var Collection<int, Operation> $failed */
        $failed = Operation::with('errors')
            ->whereNotNull('failed_at')
            ->latest('failed_at')
            ->get();

        $this->components->info('Failed Operations');

        if ($failed->isEmpty()) {
            $this->components->warn('No failed operations found.');

            return;
        }

        foreach ($failed as $operation) {
            $this->newLine();
            $this->components->error(sprintf(
                '%s (Failed at: %s)',
                $operation->name,
                $operation->failed_at?->format('Y-m-d H:i:s') ?? 'N/A',
            ));

            if (!$operation->errors->isNotEmpty()) {
                continue;
            }

            $this->table(
                ['Exception', 'Message', 'Occurred At'],
                $operation->errors->map(fn (OperationError $error): array => [
                    $error->exception,
                    $error->message,
                    $error->created_at->format('Y-m-d H:i:s'),
                ])->all(),
            );

            if (!$this->output->isVerbose()) {
                continue;
            }

            foreach ($operation->errors as $error) {
                $this->newLine();
                $this->line('<fg=red>Stack Trace:</>');
                $this->line($error->trace);
            }
        }

        $this->newLine();
        $this->components->info(sprintf('Total: %d failed operation(s)', $failed->count()));
    }
}
