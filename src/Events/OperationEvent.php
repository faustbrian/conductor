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
 * Base event class for operation lifecycle tracking.
 *
 * Provides shared structure for operation-specific events (OperationStarted,
 * OperationEnded) following Laravel's MigrationEvent pattern. Extended by
 * concrete event classes to inherit common operation instance and execution
 * method properties.
 *
 * Unlike batch-level events (OperationsStarted/OperationsEnded), this base
 * class provides access to individual operation instances, allowing listeners
 * to inspect operation metadata, payload, and dependencies.
 *
 * ```php
 * Event::listen(OperationEvent::class, function ($event) {
 *     // Listen to all operation lifecycle events
 *     Log::info("{$event->operation->name} via {$event->method->value}");
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class OperationEvent
{
    /**
     * Create a new operation lifecycle event.
     *
     * @param Operation       $operation The operation instance being executed. Provides
     *                                   access to operation metadata including name, payload,
     *                                   dependencies, and configuration for detailed logging
     *                                   and monitoring in event listeners.
     * @param ExecutionMethod $method    The execution method being used for this operation.
     *                                   Indicates whether the operation is running synchronously,
     *                                   asynchronously, in a batch, or via another execution
     *                                   strategy, enabling context-aware event handling.
     */
    public function __construct(
        public readonly Operation $operation,
        public readonly ExecutionMethod $method,
    ) {}
}
