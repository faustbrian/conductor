<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Events;

use Cline\Sequencer\Enums\ExecutionMethod;

/**
 * Event dispatched after a batch of operations completes execution.
 *
 * Mirrors Laravel's MigrationsEnded event pattern. Fired when all operations
 * in a batch have finished executing, regardless of individual success or failure.
 * All operation results have been persisted to the database at dispatch time.
 *
 * Dispatched after the entire operation batch completes. Pairs with
 * OperationsStarted for batch-level timing, cleanup, and final reporting.
 *
 * ```php
 * Event::listen(OperationsEnded::class, function ($event) {
 *     Cache::forget('operations_in_progress');
 *     Notification::send($admins, new OperationBatchCompleted($event->method));
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class OperationsEnded
{
    /**
     * Create a new batch completion event.
     *
     * @param ExecutionMethod $method The execution method used for the completed batch.
     *                                Indicates the execution strategy (sync, async, batch,
     *                                etc.) that processed the operations, enabling method-
     *                                specific cleanup or post-processing logic in listeners.
     */
    public function __construct(
        public ExecutionMethod $method,
    ) {}
}
