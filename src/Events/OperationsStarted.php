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
 * Event dispatched before a batch of operations begins execution.
 *
 * Mirrors Laravel's MigrationsStarted event pattern. Fired when an operation
 * execution command starts processing a batch but before any individual
 * operations execute. Operations have been queried and loaded at this point.
 *
 * Dispatched before batch execution begins. Pairs with OperationsEnded for
 * batch-level timing, resource preparation, and progress initialization.
 *
 * ```php
 * Event::listen(OperationsStarted::class, function ($event) {
 *     Cache::put('operations_in_progress', true, now()->addHour());
 *     Log::info("Starting operation batch via {$event->method->value}");
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class OperationsStarted
{
    /**
     * Create a new batch initialization event.
     *
     * @param ExecutionMethod $method The execution method being used for this batch.
     *                                Indicates the execution strategy (sync, async, batch,
     *                                etc.) that will process the operations, enabling
     *                                method-specific setup or resource allocation in listeners.
     */
    public function __construct(
        public ExecutionMethod $method,
    ) {}
}
