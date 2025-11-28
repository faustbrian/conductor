<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations with exception-based failure thresholds.
 *
 * Operations implementing this interface define a maximum number of exceptions that can
 * occur before the operation is permanently marked as failed. This threshold operates
 * independently from retry attempts, allowing operations to tolerate transient exceptions
 * while still failing decisively after repeated errors indicate a persistent problem.
 *
 * Sequencer tracks exception counts across all retry attempts and compares against the
 * threshold returned by maxExceptions(). Once the threshold is exceeded, the operation
 * fails immediately without further retry attempts, even if retries remain available.
 *
 * This pattern is particularly useful for operations that interact with external APIs
 * where occasional network glitches are expected but repeated failures indicate a service
 * outage or configuration problem that won't resolve through retries.
 *
 * The exception count resets for each new deployment. Exceptions from previous deployments
 * do not carry over, ensuring clean state for each orchestration run.
 *
 * Common use cases:
 * - External API integrations with flaky network connections
 * - Operations that tolerate occasional database deadlocks
 * - Third-party service calls with unpredictable availability
 * - Batch processing where some record failures are acceptable
 *
 * ```php
 * final class SyncWithExternalAPI implements Operation, HasMaxExceptions, Retryable
 * {
 *     public function maxExceptions(): int
 *     {
 *         return 3; // Tolerate 3 exceptions before giving up
 *     }
 *
 *     public function retryAfter(): int
 *     {
 *         return 30; // Retry every 30 seconds
 *     }
 *
 *     public function tries(): int
 *     {
 *         return 10; // Up to 10 retry attempts
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Will retry up to 10 times, but fail permanently after 3rd exception
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface HasMaxExceptions
{
    /**
     * Get the maximum number of exceptions allowed before permanent failure.
     *
     * Sequencer tracks cumulative exceptions across all retry attempts and compares
     * against this threshold. Once exceeded, the operation fails immediately without
     * further retries, even if retry attempts remain available.
     *
     * Return a positive integer representing the exception tolerance. Returning 1 means
     * the operation fails on the first exception. Returning 0 is invalid and will cause
     * an error.
     *
     * @return int Maximum number of exceptions before permanent failure (must be >= 1)
     */
    public function maxExceptions(): int;
}
