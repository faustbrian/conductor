<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Concerns;

use Cline\Sequencer\Contracts\Operation;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;

/**
 * Provides batch execution context and control for operations.
 *
 * This trait enables operations to participate in Laravel's bus batch system,
 * allowing access to batch status, dynamic job addition during execution, and
 * coordination between related operations within a batch context.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @phpstan-ignore-next-line trait.unused
 */
trait Batchable
{
    /**
     * The batch instance associated with this operation.
     *
     * Set by Laravel's queue system when the operation is dispatched as part
     * of a batch. Remains null for non-batched operation execution.
     */
    public ?Batch $batch = null;

    /**
     * Get the batch instance for this operation.
     *
     * Provides access to the parent batch context when this operation is being
     * executed as part of a batch. Returns null if the operation is not running
     * within a batch context.
     *
     * @return null|Batch The batch instance, or null if not executing in a batch
     */
    public function batch(): ?Batch
    {
        return $this->batch;
    }

    /**
     * Determine if this operation is executing within a batch.
     *
     * Checks whether the operation is part of a batch execution context,
     * allowing conditional logic based on batch participation. Useful for
     * operations that behave differently when batched versus standalone.
     *
     * @return bool True if the operation is executing within a batch context
     */
    public function batching(): bool
    {
        return $this->batch !== null;
    }

    /**
     * Set the batch instance for this operation.
     *
     * Associates this operation with a batch context. Called automatically by
     * Laravel's queue system when dispatching batched jobs. Manual invocation
     * is typically unnecessary and should be avoided.
     *
     * @param  Batch $batch The batch instance to associate with this operation
     * @return $this The operation instance for method chaining
     */
    public function withBatch(Batch $batch): self
    {
        $this->batch = $batch;

        return $this;
    }

    /**
     * Dynamically add operations to the current batch during execution.
     *
     * Enables fan-out patterns where additional operations are discovered during
     * execution and need to be added to the same batch. The added operations will
     * be tracked by the same batch, allowing coordinated completion callbacks and
     * error handling across all related work.
     *
     * @param  iterable<Operation> $operations Operations to add to the batch
     * @return null|PendingBatch   Pending batch for chaining, or null if not in batch context
     */
    public function addBatch(iterable $operations): ?PendingBatch
    {
        if (!$this->batch) {
            return null;
        }

        return $this->batch->add($operations);
    }
}
