<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Events;

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Enums\ExecutionMethod;
use Throwable;

/**
 * Event dispatched when an operation fails during execution.
 *
 * Fired when an operation throws an exception (other than SkipOperationException)
 * during its handle() method. The exception is captured for logging and the
 * operation state is set to failed before this event is dispatched. Enables
 * centralized error tracking, alerting, and retry decision logic.
 *
 * ```php
 * Event::listen(OperationFailed::class, function ($event) {
 *     Log::error("Operation failed", [
 *         'operation' => $event->operation->name,
 *         'error' => $event->exception->getMessage(),
 *         'duration' => $event->elapsedMs,
 *     ]);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationFailed extends OperationEvent
{
    /**
     * Create a new operation failed event.
     *
     * @param Operation       $operation The operation instance that failed during execution.
     *                                   Provides access to operation metadata for error
     *                                   tracking and failure analysis in event listeners.
     * @param ExecutionMethod $method    The execution method used for this operation.
     *                                   Indicates the execution context (sync, async,
     *                                   batch, etc.) for context-aware error handling.
     * @param Throwable       $exception The exception that caused the operation to fail.
     *                                   Contains complete error details including message,
     *                                   stack trace, and exception type. Used for logging,
     *                                   alerting, and determining retry eligibility based
     *                                   on exception type (transient vs permanent failures).
     * @param int             $elapsedMs Time taken before the operation failed, measured in
     *                                   milliseconds from operation start to exception. Used
     *                                   for timeout analysis, performance monitoring, and
     *                                   identifying operations that fail quickly vs those
     *                                   that timeout after long processing.
     */
    public function __construct(
        Operation $operation,
        ExecutionMethod $method,
        public readonly Throwable $exception,
        public readonly int $elapsedMs,
    ) {
        parent::__construct($operation, $method);
    }
}
