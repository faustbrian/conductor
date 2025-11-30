<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Enums;

/**
 * Defines execution states for operation lifecycle tracking.
 *
 * Represents all possible states an operation transitions through during its
 * lifecycle, from initial execution through various terminal states. States are
 * mutually exclusive and determined by timestamp field presence in the database.
 * Used for filtering, reporting, and determining retry eligibility.
 *
 * State precedence (evaluated highest to lowest):
 * 1. RolledBack - operation was undone after completion/failure
 * 2. Failed - operation execution threw an exception
 * 3. Skipped - operation threw SkipOperationException during execution
 * 4. Completed - operation finished successfully
 * 5. Pending - operation started but hasn't finished yet
 *
 * ```php
 * // State determination from database record
 * $state = OperationState::fromRecord($operation);
 *
 * // Filtering operations by state
 * Operation::where('completed_at', '!=', null)
 *          ->where('failed_at', null)
 *          ->get(); // Completed operations
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum OperationState: string
{
    /**
     * Operation execution started but has not reached a terminal state.
     *
     * Occurs when executed_at timestamp is set but no completion state timestamp
     * exists (completed_at, failed_at, skipped_at, rolled_back_at are all null).
     * Common during asynchronous/queued execution or when a process terminates
     * unexpectedly before completion. Pending operations are eligible for retry.
     */
    case Pending = 'pending';

    /**
     * Operation executed successfully without errors.
     *
     * The operation's handle() method completed without throwing exceptions and
     * the completed_at timestamp was set. Represents the ideal terminal state for
     * most operations. Completed operations are not eligible for automatic retry.
     */
    case Completed = 'completed';

    /**
     * Operation execution threw an unhandled exception.
     *
     * An exception was thrown during operation execution and not caught by the
     * operation's handle() method, causing the failed_at timestamp to be set.
     * Exception details are captured in the related operation_errors table with
     * full stack traces. Failed operations may be eligible for retry depending
     * on retry configuration.
     */
    case Failed = 'failed';

    /**
     * Operation was intentionally bypassed during execution.
     *
     * The operation threw SkipOperationException to indicate the work should be
     * skipped based on runtime conditions (e.g., HTTP 304 Not Modified, resource
     * already exists, preconditions not met). Sets the skipped_at timestamp and
     * is considered a successful outcome, not a failure. Skipped operations are
     * not eligible for retry.
     */
    case Skipped = 'skipped';

    /**
     * Operation was undone after completion or failure.
     *
     * The operation's rollback() method was successfully executed via the
     * Rollbackable interface, reverting the operation's changes and setting
     * the rolled_back_at timestamp. This state takes precedence over all other
     * states in determination logic. Typically occurs during transactional batch
     * rollback when a later operation fails.
     */
    case RolledBack = 'rolled_back';

    /**
     * Determine if this state represents a successful outcome.
     *
     * Both Completed and Skipped are considered successful states since
     * skipping is an intentional, valid decision rather than an error.
     *
     * @return bool True if the state represents success
     */
    public function isSuccessful(): bool
    {
        return $this === self::Completed || $this === self::Skipped;
    }

    /**
     * Determine if this state represents a terminal state.
     *
     * Terminal states are final outcomes where the operation won't change
     * state again under normal circumstances.
     *
     * @return bool True if the state is terminal
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Determine if this state represents a failure.
     *
     * @return bool True if the operation failed
     */
    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Determine if this state represents a pending operation.
     *
     * @return bool True if the operation is still pending
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Get a human-readable label for this state.
     *
     * @return string The display label
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::RolledBack => 'Rolled Back',
        };
    }

    /**
     * Get a color code for UI representation.
     *
     * @return string Color name suitable for UI frameworks
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Skipped => 'blue',
            self::RolledBack => 'orange',
        };
    }
}
