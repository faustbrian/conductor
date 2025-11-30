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
 * Event dispatched when an operation is skipped during execution.
 *
 * Fired when an operation throws SkipOperationException from within its
 * handle() method, indicating it should be skipped based on runtime conditions.
 * The skip reason is captured for logging and audit purposes.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationSkipped extends OperationEvent
{
    /**
     * Create a new operation skipped event.
     *
     * @param Operation       $operation The operation instance that was skipped
     * @param ExecutionMethod $method    The execution method being used
     * @param string          $reason    The reason the operation was skipped
     * @param int             $elapsedMs Time taken before skip in milliseconds
     */
    public function __construct(
        Operation $operation,
        ExecutionMethod $method,
        public readonly string $reason,
        public readonly int $elapsedMs,
    ) {
        parent::__construct($operation, $method);
    }
}
