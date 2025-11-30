<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Enums;

/**
 * Defines execution methods for Sequencer operations.
 *
 * Provides type-safe execution context for operations, supporting various
 * execution strategies from simple synchronous calls to complex dependency
 * graphs. Used throughout the event system for tracking how operations are
 * dispatched and executed.
 *
 * ```php
 * // Synchronous execution
 * $executor->execute($operation, ExecutionMethod::Sync);
 *
 * // Batch with failure tolerance
 * $executor->executeBatch($operations, ExecutionMethod::AllowedToFailBatch);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum ExecutionMethod: string
{
    /**
     * Synchronous execution in the current process.
     *
     * Operation executes immediately within the current request/process context,
     * blocking until completion. Best for simple operations or when immediate
     * feedback is required. No queue infrastructure needed.
     */
    case Sync = 'sync';

    /**
     * Asynchronous execution via Laravel queue system.
     *
     * Operation is dispatched to the configured queue for background processing,
     * allowing the current request to complete without waiting. Requires queue
     * workers to be running. Ideal for long-running or resource-intensive tasks.
     */
    case Async = 'async';

    /**
     * Batch execution via Laravel's bus batch system.
     *
     * Multiple operations are grouped and executed together with shared progress
     * tracking, completion callbacks, and failure handling. All operations must
     * succeed for the batch to succeed. Requires database batch storage.
     */
    case Batch = 'batch';

    /**
     * Sequential chain execution.
     *
     * Operations execute one after another in strict order, with each operation
     * waiting for the previous one to complete before starting. Useful when
     * operations have implicit ordering requirements or share mutable state.
     */
    case Chain = 'chain';

    /**
     * Dependency graph execution with wave-based parallelization.
     *
     * Operations are analyzed for dependencies and executed in topologically-sorted
     * waves. Operations within the same wave run in parallel, while operations in
     * later waves wait for their dependencies to complete. Maximizes throughput
     * while respecting explicit operation dependencies.
     */
    case DependencyGraph = 'dependency_graph';

    /**
     * Scheduled execution at a specific future time.
     *
     * Operation is queued but delayed until the specified execution timestamp.
     * Combines queue infrastructure with Laravel's scheduling system to defer
     * operation execution. Useful for time-sensitive or rate-limited operations.
     */
    case Scheduled = 'scheduled';

    /**
     * Batch execution with selective failure tolerance.
     *
     * Similar to standard Batch, but operations implementing the AllowedToFail
     * interface can fail without causing the entire batch to fail. Other operations
     * still trigger batch failure on error. Useful for optional or best-effort tasks.
     */
    case AllowedToFailBatch = 'allowed_to_fail_batch';

    /**
     * Transactional batch with automatic rollback on failure.
     *
     * All operations execute as a batch, but if any operation fails, all previously
     * completed operations are rolled back in reverse execution order. Only operations
     * implementing the Rollbackable interface participate in rollback. Provides
     * all-or-nothing semantics for operation batches.
     */
    case TransactionalBatch = 'transactional_batch';
}
