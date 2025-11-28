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
use Throwable;

/**
 * Event dispatched when an operation fails during execution.
 *
 * Fired when an operation throws an exception (other than SkipOperationException)
 * during its handle() method. The exception is captured for logging and the
 * operation state is set to failed before this event is dispatched.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationFailed extends OperationEvent
{
    /**
     * Create a new operation failed event.
     *
     * @param Operation       $operation The operation instance that failed
     * @param ExecutionMethod $method    The execution method being used
     * @param Throwable       $exception The exception that caused the failure
     * @param int             $elapsedMs Time taken before failure in milliseconds
     */
    public function __construct(
        Operation $operation,
        ExecutionMethod $method,
        public readonly Throwable $exception,
        public readonly int $elapsedMs,
    ) {
        parent::__construct($operation, $method);
    }
}
