<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\SequentialOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Support\Facades\Event;
use Throwable;

use function class_basename;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function ucfirst;

/**
 * Artisan command to process pending migrations and operations sequentially.
 *
 * This command discovers all pending migrations and operations, sorts them by
 * timestamp, and executes them in chronological order. Supports isolation mode
 * for atomic locking in multi-server environments, dry-run preview, resumption
 * from specific timestamps, and re-execution of completed operations.
 *
 * ```bash
 * # Process all pending migrations and operations
 * php artisan sequencer:process
 *
 * # Preview execution order without running tasks
 * php artisan sequencer:process --dry-run
 *
 * # Run with atomic lock to prevent concurrent execution
 * php artisan sequencer:process --isolate
 *
 * # Resume from specific timestamp
 * php artisan sequencer:process --from=2024_01_01_120000
 *
 * # Re-execute completed operations
 * php artisan sequencer:process --repeat
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ProcessCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Defines the command syntax with four optional flags:
     * - isolate: Enables atomic locking to prevent concurrent execution across multiple servers
     * - dry-run: Previews the execution order without actually running migrations or operations
     * - from: Filters tasks to only those with timestamps at or after the specified value (YYYY_MM_DD_HHMMSS format)
     * - repeat: Forces re-execution of previously completed operations, useful for idempotent operations or rollback scenarios
     *
     * @var string
     */
    protected $signature = 'sequencer:process
                            {--isolate : Use atomic lock to prevent concurrent execution}
                            {--dry-run : Preview execution order without running tasks}
                            {--from= : Resume execution from a specific timestamp (YYYY_MM_DD_HHMMSS)}
                            {--repeat : Re-execute already-completed operations (throws if operation has never been executed)}
                            {--sync : Force synchronous execution}
                            {--async : Force asynchronous execution via queue}
                            {--queue= : Dispatch to specific queue connection}
                            {--tags=* : Only run operations with specified tags}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Execute pending migrations and operations in sequential order';

    /**
     * Execute the console command to process pending migrations and operations.
     *
     * Orchestrates the execution of all pending migrations and operations in chronological
     * order based on their timestamps. Routes to dry-run mode if requested, otherwise proceeds
     * with actual execution. Respects command options for isolation, resumption from specific
     * timestamps, and re-execution of completed operations.
     *
     * @param SequentialOrchestrator $orchestrator The orchestrator service that manages task discovery,
     *                                             sorting by timestamp, dependency resolution, and sequential
     *                                             execution of both migrations and operations. Handles both
     *                                             dry-run previews and actual task execution.
     *
     * @throws Throwable When orchestration fails, task execution encounters unrecoverable errors,
     *                   or atomic locks cannot be acquired in isolation mode
     *
     * @return int Command exit code: self::SUCCESS (0) if all tasks executed successfully,
     *             self::FAILURE (1) if any errors occurred during processing or orchestration
     */
    public function handle(SequentialOrchestrator $orchestrator): int
    {
        $isolate = (bool) $this->option('isolate');
        $dryRun = (bool) $this->option('dry-run');
        $repeat = (bool) $this->option('repeat');
        $sync = (bool) $this->option('sync');
        $async = (bool) $this->option('async');
        $fromOption = $this->option('from');
        $from = is_string($fromOption) ? $fromOption : null;
        $queueOption = $this->option('queue');
        $queue = is_string($queueOption) ? $queueOption : null;
        $tags = $this->option('tags');

        if ($sync && $async) {
            $this->error('Cannot use --sync and --async together');

            return self::FAILURE;
        }

        if ($dryRun) {
            return $this->handleDryRun($orchestrator, $from, $repeat, $tags);
        }

        if ($isolate) {
            $this->info('Running with isolation lock...');
        }

        if ($from !== null) {
            $this->info('Resuming from timestamp: '.$from);
        }

        if ($repeat) {
            $this->info('Re-executing previously completed operations...');
        }

        if ($sync) {
            $this->info('Forcing synchronous execution...');
        }

        if ($async) {
            $this->info('Forcing asynchronous execution...');
        }

        if ($queue !== null) {
            $this->info('Dispatching to queue: '.$queue);
        }

        if (is_array($tags) && $tags !== []) {
            $this->info('Filtering by tags: '.implode(', ', $tags));
        }

        $this->registerProgressListeners();

        try {
            $orchestrator->process($isolate, $dryRun, $from, $repeat, $sync, $async, $queue, $tags);

            $this->newLine();
            $this->components->info('All migrations and operations processed successfully.');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->newLine();
            $this->components->error('Failed to process migrations and operations:');
            $this->error($throwable->getMessage());

            if ($this->output->isVerbose()) {
                $this->error($throwable->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Register event listeners to display progress during execution.
     */
    private function registerProgressListeners(): void
    {
        Event::listen(MigrationEnded::class, function (MigrationEnded $event): void {
            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Migration:</> %s', class_basename($event->migration)),
                '<fg=green;options=bold>DONE</>',
            );
        });

        Event::listen(OperationEnded::class, function (OperationEnded $event): void {
            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Operation:</> %s', class_basename($event->operation)),
                '<fg=green;options=bold>DONE</>',
            );
        });
    }

    /**
     * Handle dry-run mode to preview execution without running tasks.
     *
     * Discovers and displays all pending migrations and operations in a table format,
     * showing the type (migration or operation), timestamp, and descriptive name of each
     * task. This allows verification of execution order and task selection before committing
     * to actual execution, which is critical for production environments.
     *
     * @param  SequentialOrchestrator $orchestrator The orchestrator service used to discover and sort
     *                                              pending tasks by timestamp without executing them.
     *                                              Returns structured task data for display purposes.
     * @param  null|string            $from         Optional timestamp filter in YYYY_MM_DD_HHMMSS format. When provided,
     *                                              only tasks with timestamps at or after this value are shown. Useful
     *                                              for previewing partial execution or resuming from a specific point.
     * @param  bool                   $repeat       Whether to include already-completed operations for re-execution preview.
     *                                              When true, shows operations that would be re-run; when false, only
     *                                              shows truly pending tasks that have never executed.
     * @return int                    Command exit code: always returns self::SUCCESS (0) as dry-run mode never
     *                                executes tasks and cannot fail (discovery errors are caught and displayed)
     */
    private function handleDryRun(SequentialOrchestrator $orchestrator, ?string $from, bool $repeat, mixed $tags): int
    {
        $this->components->info('Dry-run mode: Previewing execution order...');
        $this->newLine();

        $tasks = $orchestrator->process(false, true, $from, $repeat, false, false, null, $tags);

        if ($tasks === null || $tasks === []) {
            $this->components->warn('No pending migrations or operations found.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            $this->components->twoColumnDetail(
                sprintf('<fg=gray>%s:</> %s', ucfirst($task['type']), $task['name']),
                '<fg=yellow;options=bold>PENDING</>',
            );
        }

        $this->newLine();
        $this->components->info(sprintf('Found %d pending task(s).', count($tasks)));

        return self::SUCCESS;
    }
}
