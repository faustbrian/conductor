<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Commands;

use Cline\Sequencer\SequencerManager;
use Illuminate\Console\Command;
use Throwable;

use function is_string;

/**
 * Artisan command to execute a single operation by name or timestamp.
 *
 * This command provides a convenient way to run individual operations without
 * processing the entire queue. Supports both timestamp-based file names and
 * fully-qualified class names. Operations can be executed synchronously or
 * asynchronously with optional database record tracking.
 *
 * ```bash
 * # Execute by timestamp
 * php artisan sequencer:execute 2024_01_01_120000_my_operation
 *
 * # Execute by class name
 * php artisan sequencer:execute App\\Operations\\MyOperation
 *
 * # Force async execution
 * php artisan sequencer:execute 2024_01_01_120000_my_operation --async
 *
 * # Force sync execution
 * php artisan sequencer:execute 2024_01_01_120000_my_operation --sync
 *
 * # Dispatch to specific queue
 * php artisan sequencer:execute 2024_01_01_120000_my_operation --queue=high-priority
 *
 * # Execute without database record
 * php artisan sequencer:execute 2024_01_01_120000_my_operation --no-record
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ExecuteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Defines the command syntax with the operation identifier as a required argument
     * and optional flags for execution mode, queue routing, and record tracking.
     *
     * @var string
     */
    protected $signature = 'sequencer:execute
                            {operation : Operation timestamp (YYYY_MM_DD_HHMMSS_name) or fully-qualified class name}
                            {--sync : Force synchronous execution}
                            {--async : Force asynchronous execution via queue}
                            {--queue= : Dispatch to specific queue connection}
                            {--no-record : Execute without creating database record}';

    /**
     * The console command description shown in artisan list output.
     *
     * @var string
     */
    protected $description = 'Execute a specific operation by name or timestamp';

    /**
     * Execute the console command to run a single operation.
     *
     * Loads and executes the specified operation using the SequencerManager. Validates
     * mutually exclusive flags (sync/async), determines execution mode, and handles
     * errors with detailed output including stack traces in verbose mode.
     *
     * @param SequencerManager $manager The Sequencer manager instance for loading and
     *                                  executing operations. Handles operation discovery,
     *                                  dependency injection, and execution coordination.
     *
     * @throws Throwable When operation loading fails, class cannot be found, or operation
     *                   execution encounters unrecoverable errors
     *
     * @return int Command exit code: self::SUCCESS (0) if operation executed successfully,
     *             self::FAILURE (1) if validation failed or operation execution encountered errors
     */
    public function handle(SequencerManager $manager): int
    {
        $operationArg = $this->argument('operation');

        // Validate operation argument type
        if (!is_string($operationArg)) {
            $this->error('Operation argument must be a string');

            return self::FAILURE;
        }

        $operation = $operationArg;
        $sync = (bool) $this->option('sync');
        $async = (bool) $this->option('async');
        $noRecord = (bool) $this->option('no-record');
        $queueOption = $this->option('queue');
        $queue = is_string($queueOption) ? $queueOption : null;

        // Validate mutually exclusive flags
        if ($sync && $async) {
            $this->error('Cannot use --sync and --async together');

            return self::FAILURE;
        }

        // Determine execution mode
        $shouldAsync = $async; // Default to sync unless --async

        // Show execution info
        $this->info('Executing operation: '.$operation);

        if ($sync) {
            $this->info('Mode: Synchronous (forced)');
        } elseif ($async) {
            $this->info('Mode: Asynchronous (forced)');
        }

        if ($queue !== null) {
            $this->info('Queue: '.$queue);
        }

        if ($noRecord) {
            $this->info('Recording: Disabled');
        }

        try {
            // Execute the operation
            // For now, queue option requires async mode
            if ($queue !== null && !$shouldAsync) {
                $this->warn('Queue specified but not in async mode. Use --async to dispatch to queue.');
            }

            $manager->execute(
                operation: $operation,
                async: $shouldAsync,
                record: !$noRecord,
                queue: $queue,
            );

            $this->info('Operation executed successfully.');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('Failed to execute operation:');
            $this->error($throwable->getMessage());

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->error('Stack trace:');
                $this->error($throwable->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
