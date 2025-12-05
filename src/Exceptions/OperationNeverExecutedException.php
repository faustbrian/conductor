<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Thrown when attempting to repeat an operation that has never been executed.
 *
 * The --repeat flag allows re-execution of previously completed operations, but it
 * requires that the operation has been executed at least once before. This exception
 * is raised when the operation has no execution history in the database, indicating
 * either a typo in the operation name or an attempt to repeat a newly created operation.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationNeverExecutedException extends RuntimeException implements SequencerException
{
    /**
     * Create exception for operation without execution history.
     *
     * Thrown when the --repeat flag is used with an operation that has no completed_at
     * or executed_at timestamp in the operations table. This prevents confusion from
     * attempting to repeat operations that haven't run yet.
     *
     * @param  string $operationName The class name of the operation that was never executed
     * @return self   Exception instance with operation name in error message
     */
    public static function cannotRepeat(string $operationName): self
    {
        return new self(
            sprintf(
                "Operation '%s' has never been executed. Cannot use --repeat flag for operations that haven't run before.",
                $operationName,
            ),
        );
    }
}
