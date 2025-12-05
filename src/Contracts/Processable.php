<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

use Throwable;

/**
 * Foundational contract for all processable tasks in the orchestration system.
 *
 * This interface establishes the common execution contract for both database migrations
 * and operations. Any task that Sequencer's orchestration engine can discover, sequence,
 * and execute must implement this interface.
 *
 * The handle() method contains the task's core logic and is called by the orchestrator
 * when the task is ready to execute. The orchestrator manages the execution context,
 * including transaction boundaries, error handling, dependency resolution, and execution
 * tracking.
 *
 * Both migrations and operations implement this interface through their respective
 * hierarchies, providing a unified abstraction for sequential task processing regardless
 * of whether the task modifies schema or performs business logic.
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 * @see Operation
 */
interface Processable
{
    /**
     * Execute the processable task's core logic.
     *
     * This method is invoked by the orchestrator when all preconditions are met:
     * dependencies satisfied, conditional checks passed, and execution order determined.
     * The method should contain the complete implementation of the task's purpose,
     * whether schema modification, data transformation, or business operation.
     *
     * @throws Throwable When execution encounters an unrecoverable error that should
     *                   halt orchestration and potentially trigger rollback
     */
    public function handle(): void;
}
