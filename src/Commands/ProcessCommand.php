<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Events\OperationFailed;
use Cline\Sequencer\Events\OperationSkipped;
use Cline\Sequencer\Events\OperationStarted;
use Cline\Sequencer\SequentialOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\Event;
use Throwable;

use function array_key_exists;
use function class_basename;
use function count;
use function hrtime;
use function implode;
use function is_array;
use function is_string;
use function number_format;
use function preg_match;
use function sprintf;
use function str_contains;
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
 * php artisan sequencer:process --isolated
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
     * Alternative command names for convenient access.
     *
     * @var array<string>
     */
    protected $aliases = ['operate'];

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
                            {--isolated : Use atomic lock to prevent concurrent execution}
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
     * Track start times for tasks to calculate elapsed time.
     *
     * @var array<string, float>
     */
    private array $taskStartTimes = [];

    /**
     * Track counts for summary.
     *
     * @var array{migrations: int, operations: int, skipped: int, failed: int}
     */
    private array $counts = [
        'migrations' => 0,
        'operations' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    /**
     * Overall start time.
     */
    private float $startTime = 0;

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
        $isolate = (bool) $this->option('isolated');
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

        $this->startTime = hrtime(true);
        $this->registerProgressListeners();

        try {
            $orchestrator->process($isolate, $dryRun, $from, $repeat, $sync, $async, $queue, $tags);

            $this->newLine();
            $this->displaySummary(true);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->newLine();
            $this->displaySummary(false);
            $this->newLine();
            $this->components->error($throwable->getMessage());

            if ($this->output->isVerbose()) {
                $this->error($throwable->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Display execution summary with counts and total duration.
     *
     * Calculates total execution time and formats a summary message showing the counts
     * of migrations, operations, skipped tasks, and failed tasks. Displays with success
     * or error styling based on execution outcome.
     *
     * @param bool $success Whether the overall execution succeeded without errors
     */
    private function displaySummary(bool $success): void
    {
        $totalTime = (hrtime(true) - $this->startTime) / 1e9;
        $totalTasks = $this->counts['migrations'] + $this->counts['operations'];

        $parts = [];

        if ($this->counts['migrations'] > 0) {
            $parts[] = sprintf('%d migration(s)', $this->counts['migrations']);
        }

        if ($this->counts['operations'] > 0) {
            $parts[] = sprintf('%d operation(s)', $this->counts['operations']);
        }

        if ($this->counts['skipped'] > 0) {
            $parts[] = sprintf('%d skipped', $this->counts['skipped']);
        }

        if ($this->counts['failed'] > 0) {
            $parts[] = sprintf('%d failed', $this->counts['failed']);
        }

        if ($totalTasks === 0) {
            $this->components->info('No pending migrations or operations found.');

            return;
        }

        $summary = sprintf(
            'Processed %s in %ss',
            implode(', ', $parts),
            number_format($totalTime, 2),
        );

        if ($success) {
            $this->components->info($summary);
        } else {
            $this->components->error($summary);
        }
    }

    /**
     * Register event listeners to display progress during execution.
     *
     * Listens for migration and operation lifecycle events (started, ended, skipped, failed)
     * and displays real-time progress updates with two-column formatting showing task name
     * and status. Updates internal counters for summary display and tracks elapsed time
     * for performance reporting.
     */
    private function registerProgressListeners(): void
    {
        Event::listen(MigrationStarted::class, function (MigrationStarted $event): void {
            $name = $this->getClassName($event->migration);
            $this->taskStartTimes[$name] = hrtime(true);

            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Migration:</> %s', $name),
                '<fg=blue;options=bold>RUNNING</>',
            );
        });

        Event::listen(MigrationEnded::class, function (MigrationEnded $event): void {
            $name = $this->getClassName($event->migration);
            $elapsed = $this->getElapsedTime($name);
            ++$this->counts['migrations'];

            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Migration:</> %s', $name),
                sprintf('<fg=green;options=bold>DONE</> <fg=gray>(%ss)</>', $elapsed),
            );
        });

        Event::listen(OperationStarted::class, function (OperationStarted $event): void {
            $name = $this->getClassName($event->operation);

            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Operation:</> %s', $name),
                '<fg=blue;options=bold>RUNNING</>',
            );
        });

        Event::listen(OperationEnded::class, function (OperationEnded $event): void {
            $name = $this->getClassName($event->operation);
            $elapsed = $this->formatElapsedMs($event->elapsedMs);
            ++$this->counts['operations'];

            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Operation:</> %s', $name),
                sprintf('<fg=green;options=bold>DONE</> <fg=gray>(%s)</>', $elapsed),
            );
        });

        Event::listen(OperationSkipped::class, function (OperationSkipped $event): void {
            $name = $this->getClassName($event->operation);
            $elapsed = $this->formatElapsedMs($event->elapsedMs);
            ++$this->counts['skipped'];

            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Operation:</> %s', $name),
                sprintf('<fg=yellow;options=bold>SKIPPED</> <fg=gray>(%s)</>', $elapsed),
            );
        });

        Event::listen(OperationFailed::class, function (OperationFailed $event): void {
            $name = $this->getClassName($event->operation);
            $elapsed = $this->formatElapsedMs($event->elapsedMs);
            ++$this->counts['failed'];

            $this->components->twoColumnDetail(
                sprintf('<fg=gray>Operation:</> %s', $name),
                sprintf('<fg=red;options=bold>FAILED</> <fg=gray>(%s)</>', $elapsed),
            );
        });
    }

    /**
     * Format elapsed milliseconds to a human-readable string.
     *
     * Converts millisecond durations to seconds when >= 1000ms for improved readability.
     *
     * @param  int    $elapsedMs The elapsed time in milliseconds
     * @return string Formatted time string (e.g., "1.25s" or "850ms")
     */
    private function formatElapsedMs(int $elapsedMs): string
    {
        if ($elapsedMs >= 1_000) {
            return number_format($elapsedMs / 1_000, 2).'s';
        }

        return $elapsedMs.'ms';
    }

    /**
     * Get elapsed time for a task in seconds, formatted to 2 decimal places.
     *
     * Calculates duration from stored start time in taskStartTimes array. Returns "0.00"
     * if no start time was recorded for the task.
     *
     * @param  string $name The task name used as the key in taskStartTimes
     * @return string Formatted elapsed time in seconds (e.g., "1.25" for 1.25 seconds)
     */
    private function getElapsedTime(string $name): string
    {
        if (!array_key_exists($name, $this->taskStartTimes)) {
            return '0.00';
        }

        $elapsed = (hrtime(true) - $this->taskStartTimes[$name]) / 1e9;

        return number_format($elapsed, 2);
    }

    /**
     * Get a clean class name, handling anonymous classes.
     *
     * Extracts the base class name from a fully qualified class name. For anonymous
     * classes generated from migration files, extracts the filename from the path
     * embedded in the class name.
     *
     * @param  object $instance The object instance to get the class name from
     * @return string The base class name without namespace (e.g., "CreateUsersTable" or
     *                "2024_01_15_143022_create_users_table" for anonymous migrations)
     */
    private function getClassName(object $instance): string
    {
        $class = $instance::class;

        // Handle anonymous classes (e.g., "class@anonymous/path/to/file.php:7$4c3")
        if (!str_contains($class, '@anonymous')) {
            return class_basename($class);
        }

        // Extract filename from the path in the anonymous class name
        if (preg_match('#([^/\\\\]+)\.php#', $class, $matches)) {
            return $matches[1];
        }

        return class_basename($class);
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
     * @param  mixed                  $tags         Optional tags filter to limit preview to operations with specified tags.
     *                                              Expects array of tag strings when provided by command option parser.
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
