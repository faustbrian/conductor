<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

/**
 * Thrown when attempting to roll back an operation that doesn't support rollback.
 *
 * Operations must implement the Rollbackable interface to support rollback functionality.
 * This exception is raised when the sequencer:rollback command or Sequencer::rollback()
 * method is called on an operation that doesn't implement the required interface and
 * rollback() method.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationNotRollbackableException extends RuntimeException implements SequencerException
{
    /**
     * Create exception for operation missing Rollbackable interface.
     *
     * Thrown when attempting to roll back an operation that doesn't implement the
     * Rollbackable contract. Operations must explicitly opt-in to rollback support
     * by implementing the interface and defining a rollback() method.
     *
     * @return self Exception instance with interface requirement error message
     */
    public static function doesNotImplementInterface(): self
    {
        return new self('Operation does not implement Rollbackable interface');
    }
}
