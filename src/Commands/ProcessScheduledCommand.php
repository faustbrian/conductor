<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Scheduled;
use Cline\Sequencer\Orchestrators\ScheduledOrchestrator;
use Cline\Sequencer\Support\OperationDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

use function array_filter;
use function array_values;
use function count;
use function is_string;
use function sprintf;

/**
 * Process scheduled operations that are ready to execute.
 *
 * This command should be run every minute via the Laravel scheduler to check
 * for scheduled operations whose execution time has arrived. Only operations
 * implementing the Scheduled interface are considered, and they are dispatched
 * via the queue system when their scheduled time is reached or has passed.
 *
 * ```php
 * // Add to app/Console/Kernel.php
 * protected function schedule(Schedule $schedule)
 * {
 *     $schedule->command('sequencer:scheduled')->everyMinute();
 * }
 * ```
 *
 * ```bash
 * # Run manually to check for due scheduled operations
 * php artisan sequencer:scheduled
 *
 * # Filter operations from specific timestamp
 * php artisan sequencer:scheduled --from=2024_01_01_120000
 *
 * # Re-execute completed scheduled operations
 * php artisan sequencer:scheduled --repeat
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ProcessScheduledCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Supports optional flags for filtering and re-execution:
     * - from: Limits operations to those with timestamps at or after the specified value (YYYY_MM_DD_HHMMSS format)
     * - repeat: Includes previously completed operations for re-execution if they are due again
     *
     * @var string
     */
    protected $signature = 'sequencer:scheduled
                            {--from= : Resume from specific timestamp}
                            {--repeat : Re-execute already-completed operations}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Process scheduled operations that are ready for execution';

    /**
     * Create a new scheduled operation processing command instance.
     *
     * @param ScheduledOrchestrator $orchestrator the orchestrator service responsible for discovering,
     *                                            filtering, and dispatching scheduled operations to the
     *                                            queue system with appropriate delays based on their
     *                                            scheduled execution times
     * @param OperationDiscovery    $discovery    the discovery service that scans filesystem paths to find
     *                                            all available operations, determines their pending status,
     *                                            and provides metadata including class paths, names, and
     *                                            timestamps for filtering and processing
     */
    public function __construct(
        private readonly ScheduledOrchestrator $orchestrator,
        private readonly OperationDiscovery $discovery,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command to process scheduled operations.
     *
     * Discovers all pending operations, filters for those implementing the Scheduled interface,
     * checks if their scheduled execution time has arrived, and dispatches due operations via
     * the orchestrator. Respects command options for timestamp filtering and re-execution.
     *
     * @throws Throwable When the orchestrator encounters errors during operation dispatch,
     *                   queue configuration is invalid, or scheduled operations fail validation
     *
     * @return int Command exit code: self::SUCCESS (0) if operations are dispatched successfully
     *             or no operations are due, self::FAILURE (1) if orchestration or dispatch fails
     */
    public function handle(): int
    {
        $this->components->info('Checking for scheduled operations...');

        $fromOption = $this->option('from');
        $repeatOption = $this->option('repeat');

        // Cast options to their expected types
        $from = is_string($fromOption) ? $fromOption : null;
        $repeat = (bool) $repeatOption;

        // Get all pending operations from discovery service
        $operations = $this->discovery->getPending($repeat);

        // Apply timestamp filter if specified
        if ($from !== null) {
            $operations = array_filter($operations, fn (array $op): bool => $op['timestamp'] >= $from);
        }

        // Filter for operations that implement Scheduled interface and are due for execution
        $scheduledOperations = $this->getScheduledOperationsDue(array_values($operations));

        if ($scheduledOperations === []) {
            $this->components->info('No scheduled operations due for execution');

            return self::SUCCESS;
        }

        $count = count($scheduledOperations);
        $this->components->info(sprintf('Found %d scheduled operation(s) ready for execution', $count));

        // Dispatch scheduled operations via orchestrator
        try {
            $this->orchestrator->process(from: $from, repeat: $repeat);

            $this->components->success('Scheduled operations dispatched successfully');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->components->error('Failed to dispatch scheduled operations');
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Get scheduled operations that are due for execution.
     *
     * Filters the provided operations to only include those that implement the Scheduled
     * interface and whose scheduled execution time has arrived or passed. Loads each operation
     * class, checks if it implements Scheduled, compares its execution time against the current
     * time, and displays scheduled time for operations that are due.
     *
     * @param  list<array{class: string, name: string, timestamp: string}>                       $operations array of operation metadata
     *                                                                                                       containing class paths, descriptive
     *                                                                                                       names, and file timestamps for all
     *                                                                                                       pending operations discovered by
     *                                                                                                       the discovery service
     * @return list<array{class: string, name: string, timestamp: string, operation: Operation}> Array of due operations
     *                                                                                           with the loaded operation instance added to each entry. Only includes operations implementing Scheduled
     *                                                                                           whose executeAt() time is now or in the past, ready for immediate dispatch to the queue system.
     */
    private function getScheduledOperationsDue(array $operations): array
    {
        $due = [];
        $now = Date::now();

        foreach ($operations as $operationData) {
            /** @var Operation $operation */
            $operation = require $operationData['class'];

            // Skip operations that don't implement the Scheduled interface
            if (!$operation instanceof Scheduled) {
                continue;
            }

            $executeAt = $operation->executeAt();

            // Operation is due if scheduled execution time is now or in the past
            if ($executeAt > $now) {
                continue;
            }

            $due[] = [
                ...$operationData,
                'operation' => $operation,
            ];

            $this->components->twoColumnDetail(
                $operationData['name'],
                sprintf('<fg=yellow>Scheduled for %s</>', $executeAt->format('Y-m-d H:i:s')),
            );
        }

        return $due;
    }
}
