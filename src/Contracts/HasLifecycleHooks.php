<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

use Throwable;

/**
 * Contract for operations with lifecycle event hooks.
 *
 * Operations implementing this interface can define callbacks that execute at specific
 * points in the operation lifecycle: before execution begins, after successful completion,
 * and when execution fails. These hooks enable setup/teardown logic, logging, metrics
 * collection, notification sending, and cleanup operations without cluttering the main
 * handle() method.
 *
 * Lifecycle execution order:
 * 1. before() - Runs immediately before handle(), even if shouldRun() returns false
 * 2. handle() - Main operation logic (from Operation interface)
 * 3. after() - Runs only if handle() completes without throwing exceptions
 * 4. failed() - Runs if handle() throws any exception, receives the exception instance
 *
 * All hooks execute synchronously within the operation's execution context. Exceptions
 * thrown from before() prevent handle() from executing. Exceptions from after() or failed()
 * are logged but don't affect the operation's completion status.
 *
 * Common use cases:
 * - Acquiring and releasing locks or resources
 * - Starting and stopping performance timers
 * - Logging operation start, completion, and failures
 * - Sending notifications about operation status
 * - Cleaning up temporary files or database records
 * - Recording metrics and telemetry data
 *
 * ```php
 * final class SyncExternalData implements Operation, HasLifecycleHooks
 * {
 *     private float $startTime;
 *
 *     public function before(): void
 *     {
 *         $this->startTime = microtime(true);
 *         Cache::lock('sync-external-data')->get();
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Sync logic
 *     }
 *
 *     public function after(): void
 *     {
 *         $duration = microtime(true) - $this->startTime;
 *         Log::info('Sync completed', ['duration' => $duration]);
 *         Cache::lock('sync-external-data')->release();
 *     }
 *
 *     public function failed(\Throwable $exception): void
 *     {
 *         Log::error('Sync failed', ['error' => $exception->getMessage()]);
 *         Cache::lock('sync-external-data')->release();
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface HasLifecycleHooks
{
    /**
     * Execute setup logic immediately before the operation's handle() method runs.
     *
     * This hook executes even if shouldRun() returns false for operations implementing
     * ConditionalExecution. Use for acquiring locks, starting timers, establishing
     * connections, or preparing resources needed for execution.
     *
     * Exceptions thrown from this method prevent handle() from executing and mark the
     * operation as failed without calling the failed() hook.
     */
    public function before(): void;

    /**
     * Execute cleanup logic after the operation completes successfully.
     *
     * This hook runs only if handle() completes without throwing exceptions. Use for
     * releasing locks, stopping timers, logging success, sending completion notifications,
     * or cleaning up temporary resources.
     *
     * Exceptions thrown from this method are logged but don't change the operation's
     * success status. The operation remains marked as completed.
     */
    public function after(): void;

    /**
     * Execute error handling logic when the operation fails.
     *
     * This hook receives the exception that caused the failure and runs only if handle()
     * throws an exception. Use for logging errors, sending failure notifications, releasing
     * locks, cleaning up partial changes, or recording failure metrics.
     *
     * Exceptions thrown from this method are logged but don't affect the operation's
     * failure status. The original exception remains the failure cause.
     *
     * @param Throwable $exception The exception that caused the operation to fail
     */
    public function failed(Throwable $exception): void;
}
