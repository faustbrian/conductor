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
 * Event dispatched when no pending operations exist for execution.
 *
 * Mirrors Laravel's NoPendingMigrations event pattern. Fired when an operation
 * execution command runs but finds no unexecuted operations in the queue. Useful
 * for logging no-op runs, metrics tracking, or conditional automation that should
 * only trigger when work is available.
 *
 * Dispatched by operation commands when the pending operation query returns empty.
 *
 * ```php
 * Event::listen(NoPendingOperations::class, function ($event) {
 *     Log::info("No pending operations for {$event->method->value}");
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class NoPendingOperations
{
    /**
     * Create a new event instance.
     *
     * @param ExecutionMethod $method The execution method that attempted to process
     *                                operations but found none pending. Indicates the
     *                                context in which the no-op check occurred (sync,
     *                                async, batch, etc.).
     */
    public function __construct(
        public ExecutionMethod $method,
    ) {}
}
