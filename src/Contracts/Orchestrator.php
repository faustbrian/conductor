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
 * Contract for orchestrators that coordinate migration and operation execution.
 *
 * Orchestrators control the execution flow and sequencing of migrations and operations,
 * implementing specific execution strategies while maintaining consistent error handling,
 * rollback semantics, and state tracking across the entire deployment lifecycle.
 *
 * Sequencer provides multiple orchestrator implementations for different use cases:
 * - SequentialOrchestrator: Executes tasks in timestamp order, one at a time
 * - ParallelOrchestrator: Executes independent tasks concurrently for faster deployments
 * - DependencyOrchestrator: Resolves and respects explicit task dependencies
 * - ScheduledOrchestrator: Delays execution until specified future times
 *
 * All orchestrators share common execution capabilities including dry-run mode for
 * previewing changes, isolation locks for multi-server safety, resumption from specific
 * timestamps, and forced re-execution of previously completed tasks. These features
 * enable safe deployment workflows in production environments.
 *
 * Orchestrators maintain execution state in the database, tracking completed migrations
 * and operations to prevent duplicate execution and enable audit trails. They coordinate
 * rollback procedures when failures occur and integrate with Laravel's queue system for
 * asynchronous operation execution.
 *
 * Common use cases:
 * - Sequential deployments requiring strict ordering
 * - Parallel execution for independent database changes
 * - Complex dependency graphs requiring topological sorting
 * - Scheduled maintenance windows for time-sensitive operations
 * - Development previews using dry-run mode
 * - Multi-server deployments with isolation locks
 *
 * ```php
 * // Execute all pending tasks with isolation
 * $orchestrator->process(isolate: true);
 *
 * // Preview what would execute without running
 * $tasks = $orchestrator->process(dryRun: true);
 *
 * // Resume from specific timestamp after failure
 * $orchestrator->process(from: '2024_01_15_120000');
 *
 * // Force re-execution of completed tasks
 * $orchestrator->process(repeat: true);
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Orchestrator
{
    /**
     * Execute pending migrations and operations using this orchestrator's strategy.
     *
     * Processes all pending migrations and operations according to the orchestrator's
     * execution strategy (sequential, parallel, dependency-based, or scheduled). The
     * method handles task discovery, ordering, execution, error handling, state tracking,
     * and rollback coordination for the entire deployment lifecycle.
     *
     * In dry-run mode, returns an array of tasks that would execute without actually
     * running them, enabling safe preview of deployment changes. In normal execution,
     * returns null after all tasks complete successfully.
     *
     * Isolation mode acquires an atomic lock preventing concurrent execution across
     * multiple servers or processes, ensuring deployment safety in distributed
     * environments. The lock is automatically released when execution completes.
     *
     * The from parameter enables resuming execution from a specific timestamp after
     * failures or interruptions, skipping tasks that completed before the specified
     * point. The repeat parameter forces re-execution of previously completed tasks,
     * useful for testing or fixing partial deployments.
     *
     * The forceSync parameter forces synchronous execution of all operations,
     * ignoring any Asynchronous interface implementations. This is useful when
     * operations must complete before migrations continue (e.g., during event-driven
     * execution interleaved with migrations).
     *
     * @param bool        $isolate   Acquire atomic lock to prevent concurrent execution
     *                               across multiple servers or processes
     * @param bool        $dryRun    Preview tasks without executing them, returning the
     *                               list of pending migrations and operations
     * @param null|string $from      Resume execution from specific timestamp in format
     *                               YYYY_MM_DD_HHMMSS, skipping earlier completed tasks
     * @param bool        $repeat    Force re-execution of already-completed tasks,
     *                               ignoring execution history
     * @param bool        $forceSync Force synchronous execution of all operations,
     *                               overriding Asynchronous interface
     *
     * @throws Throwable When task execution fails, dependency resolution errors occur,
     *                   or the orchestrator encounters unrecoverable errors
     *
     * @return null|list<array{type: string, timestamp: string, name: string}> Array of
     *                                                                         pending tasks in dry-run mode, null during normal execution
     */
    public function process(bool $isolate = false, bool $dryRun = false, ?string $from = null, bool $repeat = false, bool $forceSync = false): ?array;
}
