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
 * Event dispatched after an individual operation completes execution.
 *
 * Mirrors Laravel's MigrationEnded event pattern. Fired immediately after an
 * operation's handle() method finishes successfully. The operation's final
 * state has been persisted to the database at dispatch time.
 *
 * Dispatched after operation execution completes and state is persisted. Pairs
 * with OperationStarted to bracket individual operation execution for timing
 * measurements and completion tracking.
 *
 * ```php
 * Event::listen(OperationEnded::class, function ($event) {
 *     Metrics::timing("operation.duration", $event->elapsedMs);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationEnded extends OperationEvent
{
    /**
     * Create a new operation ended event.
     *
     * @param Operation       $operation The operation instance that completed execution.
     *                                   Provides access to operation metadata for event
     *                                   listeners tracking completion events.
     * @param ExecutionMethod $method    The execution method used for this operation.
     *                                   Indicates the execution context (sync, async,
     *                                   batch, etc.) for context-aware metrics tracking.
     * @param int             $elapsedMs Time taken to execute the operation in milliseconds,
     *                                   measured from operation start to completion. Used
     *                                   for performance monitoring, SLA tracking, and
     *                                   identifying slow operations requiring optimization.
     */
    public function __construct(
        Operation $operation,
        ExecutionMethod $method,
        public readonly int $elapsedMs,
    ) {
        parent::__construct($operation, $method);
    }
}
