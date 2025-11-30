<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

/**
 * Thrown to gracefully skip operation execution based on runtime conditions.
 *
 * Operations can throw this exception during handle() to signal that execution
 * should be skipped without marking the operation as failed. Unlike ConditionalExecution
 * which makes skip decisions before execution begins, this exception allows runtime
 * decisions based on conditions discovered during execution.
 *
 * When thrown, the operation is marked as completed (not failed) and logged as skipped.
 * This is useful for idempotent operations that detect work has already been done,
 * API calls that return 304 Not Modified, or operations that discover runtime conditions
 * that make execution unnecessary.
 *
 * ```php
 * // Example: Skip if record already exists
 * class CreateUserOperation extends Operation
 * {
 *     public function handle(): void
 *     {
 *         // Check after acquiring lock to avoid race conditions
 *         if (User::where('email', $this->email)->exists()) {
 *             throw SkipOperationException::recordExists();
 *         }
 *
 *         User::create(['email' => $this->email]);
 *     }
 * }
 *
 * // Example: Skip based on API response
 * class SyncExternalDataOperation extends Operation
 * {
 *     public function handle(): void
 *     {
 *         $response = Http::get($this->url, [
 *             'If-Modified-Since' => $this->lastSync,
 *         ]);
 *
 *         if ($response->status() === 304) {
 *             throw SkipOperationException::notModified();
 *         }
 *
 *         $this->processData($response->json());
 *     }
 * }
 *
 * // Example: Skip based on runtime condition
 * class ProcessPaymentOperation extends Operation
 * {
 *     public function handle(): void
 *     {
 *         if ($this->payment->status === 'completed') {
 *             throw SkipOperationException::alreadyProcessed();
 *         }
 *
 *         if ($this->payment->amount <= 0) {
 *             throw SkipOperationException::conditionNotMet('amount must be positive');
 *         }
 *
 *         // Process payment...
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SkipOperationException extends RuntimeException
{
    /**
     * Create exception with custom skip reason.
     *
     * Use this factory method when none of the predefined skip reasons match your
     * use case. The reason will be logged to help track why operations are being
     * skipped during execution.
     *
     * @param  string $reason Descriptive explanation of why the operation is being skipped
     * @return self   Exception instance with custom skip reason
     */
    public static function create(string $reason = 'Operation skipped'): self
    {
        return new self($reason);
    }

    /**
     * Skip operation because work has already been completed.
     *
     * Thrown when an idempotent operation detects that the work it was going to
     * perform has already been done. Common in scenarios like payment processing,
     * data imports, or notification sending where duplicate execution must be avoided.
     *
     * @return self Exception instance with already processed message
     */
    public static function alreadyProcessed(): self
    {
        return new self('Operation already processed');
    }

    /**
     * Skip operation because external resource has not been modified.
     *
     * Thrown when an API or external service indicates data has not changed since
     * the last sync (typically via HTTP 304 Not Modified response or ETag comparison).
     * Prevents unnecessary data processing and reduces API load.
     *
     * @return self Exception instance with not modified message
     */
    public static function notModified(): self
    {
        return new self('Resource not modified');
    }

    /**
     * Skip operation because target record already exists.
     *
     * Thrown when attempting to create a record but discovering it already exists
     * in the database. Often used after acquiring a lock to prevent race conditions
     * in concurrent operation execution.
     *
     * @return self Exception instance with record exists message
     */
    public static function recordExists(): self
    {
        return new self('Record already exists');
    }

    /**
     * Skip operation because a required condition was not satisfied.
     *
     * Thrown when runtime validation determines that preconditions for execution
     * are not met. Unlike validation failures which represent errors, these are
     * legitimate reasons to skip processing (e.g., amount too small to process,
     * user opted out, feature disabled).
     *
     * @param  string $condition Description of which condition was not met
     * @return self   Exception instance with condition details
     */
    public static function conditionNotMet(string $condition): self
    {
        return new self('Condition not met: '.$condition);
    }
}
