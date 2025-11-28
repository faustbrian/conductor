<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

use DateTimeInterface;

/**
 * Contract for operations that support automatic retry on failure.
 *
 * Operations implementing this interface define retry behavior including maximum attempts,
 * backoff strategy, and time-based retry limits. Sequencer automatically retries failed
 * operations according to these specifications, recording each attempt and its outcome
 * for debugging and monitoring purposes.
 *
 * Retry behavior applies to operations that fail due to transient issues like network
 * timeouts, temporary service unavailability, rate limiting, or database deadlocks. Each
 * retry attempt is logged with the exception details, enabling post-mortem analysis of
 * failure patterns and resolution timing.
 *
 * Backoff strategies prevent overwhelming struggling services by introducing delays between
 * retry attempts. Operations can use fixed backoff (same delay between all attempts),
 * progressive backoff (increasing delays), or custom delay patterns. The backoff() method
 * returns either a single integer for uniform delays or an array specifying per-attempt
 * delays for granular control.
 *
 * Time-based retry limits enable operations to fail fast when they cannot complete within
 * a specific time window, preventing indefinite retry loops for time-sensitive deployments.
 * The retryUntil() method sets an absolute deadline after which all retry attempts cease
 * regardless of remaining tries.
 *
 * Common use cases:
 * - External API calls with rate limiting or transient failures
 * - Database operations subject to deadlocks or connection timeouts
 * - File operations on networked storage with intermittent availability
 * - Queue operations during high load or worker scaling events
 * - Search index updates during reindexing or maintenance windows
 *
 * ```php
 * final class SyncProductCatalogWithAPI implements Operation, Retryable
 * {
 *     public function tries(): int
 *     {
 *         return 5; // Allow up to 5 execution attempts
 *     }
 *
 *     public function backoff(): array|int
 *     {
 *         return [1, 5, 15, 60]; // Progressive backoff: 1s, 5s, 15s, 60s
 *     }
 *
 *     public function retryUntil(): ?\DateTimeInterface
 *     {
 *         return now()->addMinutes(10); // Stop retrying after 10 minutes
 *     }
 *
 *     public function handle(): void
 *     {
 *         // API call that may fail transiently
 *         Http::retry(3, 100)->get('https://api.example.com/products');
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Retryable
{
    /**
     * Get the maximum number of execution attempts before permanent failure.
     *
     * Defines how many times Sequencer will attempt to execute the operation before
     * marking it as permanently failed. Each attempt is logged with exception details
     * and timestamps for debugging. The first execution counts as attempt 1, so
     * returning 3 allows the initial attempt plus 2 retries.
     *
     * Failed operations that exhaust all retry attempts are marked as permanently
     * failed in the operation errors table and will not execute again unless manually
     * retried or the deployment is repeated.
     *
     * @return int Maximum number of execution attempts including initial try
     */
    public function tries(): int;

    /**
     * Get the delay in seconds before retrying failed execution attempts.
     *
     * Defines the backoff strategy by returning either a single integer for uniform
     * delays or an array of integers for per-attempt delays. For array backoff, each
     * element specifies the delay before the corresponding retry (first element is
     * delay before retry 1, second element before retry 2, etc.).
     *
     * If the array contains fewer elements than maximum retries, the last element
     * value is used for remaining attempts. Return 0 or an empty array for immediate
     * retry without delay, though this is rarely recommended for production systems.
     *
     * @return array<int>|int Single delay in seconds for all retries, or array of
     *                        per-attempt delays for progressive backoff patterns
     */
    public function backoff(): array|int;

    /**
     * Get the absolute deadline after which no more retry attempts occur.
     *
     * Establishes a time-based limit for retry attempts independent of the maximum
     * tries count. Once the deadline is reached, Sequencer marks the operation as
     * permanently failed even if retry attempts remain. This prevents indefinite
     * retry loops for time-sensitive operations that must complete within specific
     * deployment windows.
     *
     * Return null to disable time-based limits and rely solely on the tries count.
     * Useful for operations where eventual success matters more than completion
     * timing, or when retry delays make time limits impractical.
     *
     * @return null|DateTimeInterface Absolute deadline for all retry attempts,
     *                                or null to disable time-based retry limits
     */
    public function retryUntil(): ?DateTimeInterface;
}
