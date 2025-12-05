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

/**
 * Event dispatched when an operation is skipped during execution.
 *
 * Fired when an operation throws SkipOperationException from within its
 * handle() method, indicating it should be skipped based on runtime conditions.
 * The skip reason is captured for logging and audit purposes. Skipping is
 * considered a successful outcome, not a failure, and enables conditional
 * operation execution based on runtime state.
 *
 * ```php
 * Event::listen(OperationSkipped::class, function ($event) {
 *     Log::info("Operation skipped: {$event->reason}", [
 *         'operation' => $event->operation->name,
 *     ]);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationSkipped extends OperationEvent
{
    /**
     * Create a new operation skipped event.
     *
     * @param Operation       $operation The operation instance that was skipped during
     *                                   execution. Provides access to operation metadata
     *                                   for tracking skip patterns and audit logging.
     * @param ExecutionMethod $method    The execution method used for this operation.
     *                                   Indicates the execution context (sync, async,
     *                                   batch, etc.) for context-aware skip tracking.
     * @param string          $reason    Human-readable explanation of why the operation
     *                                   was skipped. Typically describes the runtime
     *                                   condition that triggered the skip (e.g., "Resource
     *                                   already exists", "HTTP 304 Not Modified", "Preconditions
     *                                   not met"). Used for logging, debugging, and understanding
     *                                   skip patterns in production.
     * @param int             $elapsedMs Time taken before the operation was skipped, measured
     *                                   in milliseconds from operation start to skip decision.
     *                                   Used for performance tracking and identifying operations
     *                                   that spend significant time before deciding to skip.
     */
    public function __construct(
        Operation $operation,
        ExecutionMethod $method,
        public readonly string $reason,
        public readonly int $elapsedMs,
    ) {
        parent::__construct($operation, $method);
    }
}
