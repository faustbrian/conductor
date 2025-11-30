<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations with maximum execution time limits.
 *
 * Operations implementing this interface specify a maximum execution duration and define
 * behavior when that limit is exceeded. Sequencer monitors operation execution time and
 * terminates operations that exceed their timeout, preventing resource exhaustion, queue
 * worker blocking, and indefinite hangs from unresponsive external services or infinite
 * loops.
 *
 * Timeout enforcement occurs at the orchestrator level, not within the operation itself.
 * When the timeout expires, Sequencer terminates the operation process and either marks
 * it as failed or triggers retry logic based on the failOnTimeout() configuration and
 * whether the operation implements the Retryable interface.
 *
 * The timeout duration should account for normal execution time plus a safety margin to
 * prevent false positives during load spikes or resource contention. Operations with
 * variable execution time (processing unknown data volumes, external API calls, etc.)
 * should set conservative timeouts allowing for worst-case scenarios.
 *
 * Timeout behavior interacts with retry configuration when the operation implements
 * Retryable. With failOnTimeout() returning false, timeouts trigger retry attempts
 * according to the retry backoff strategy. With failOnTimeout() returning true,
 * timeouts immediately mark the operation as permanently failed without retry.
 *
 * Common use cases:
 * - External API calls with unpredictable response times
 * - Data processing operations on unknown data volumes
 * - Database queries that may encounter lock contention
 * - File system operations on networked storage
 * - Operations susceptible to infinite loops or hangs
 * - Batch processing with per-item time budgets
 *
 * ```php
 * final class ImportProductsFromSupplierFeed implements Operation, Timeoutable, Retryable
 * {
 *     public function timeout(): int
 *     {
 *         return 300; // Terminate after 5 minutes
 *     }
 *
 *     public function failOnTimeout(): bool
 *     {
 *         return false; // Allow retries on timeout
 *     }
 *
 *     public function tries(): int
 *     {
 *         return 3; // Retry up to 3 times
 *     }
 *
 *     public function backoff(): array|int
 *     {
 *         return [60, 300]; // Wait 1min, then 5min between retries
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Import may timeout on large feeds but will retry
 *         Http::timeout(240)->get('https://supplier.example.com/feed.xml');
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Timeoutable
{
    /**
     * Get the maximum execution duration in seconds before timeout.
     *
     * Defines the longest time the operation can execute before Sequencer terminates it.
     * The timeout applies to the handle() method execution only, not to time spent
     * waiting in the queue or during pre-execution checks.
     *
     * Set this value conservatively to account for normal execution time plus a safety
     * margin for load spikes, resource contention, or temporary slowdowns. Operations
     * that timeout frequently indicate the limit is too aggressive or the operation
     * needs optimization.
     *
     * @return int Maximum execution time in seconds before forced termination
     */
    public function timeout(): int;

    /**
     * Determine whether timeout should cause immediate permanent failure.
     *
     * Controls whether timeout triggers retry logic or immediately fails the operation.
     * When true, timeouts mark the operation as permanently failed without retry attempts,
     * appropriate for operations where timeout indicates a fundamental problem rather than
     * a transient issue.
     *
     * When false and the operation implements Retryable, timeouts trigger retry attempts
     * according to the retry backoff strategy. This allows recovery from temporary
     * resource constraints or external service slowdowns.
     *
     * @return bool True to fail permanently on timeout, false to allow retry attempts
     */
    public function failOnTimeout(): bool;
}
