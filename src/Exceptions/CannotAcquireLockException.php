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
 * Thrown when Sequencer cannot acquire a lock within the configured timeout period.
 *
 * This exception occurs when attempting to acquire a distributed lock for operation
 * execution but the lock is held by another process and the timeout expires. Prevents
 * concurrent execution of operations that require exclusive access.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CannotAcquireLockException extends RuntimeException
{
    /**
     * Create exception for lock acquisition timeout.
     *
     * Thrown when the configured lock timeout period expires while waiting for
     * a lock to become available, indicating another process is holding the lock.
     *
     * @return self Exception instance with timeout error message
     */
    public static function timeoutExceeded(): self
    {
        return new self('Could not acquire sequencer lock within timeout period');
    }
}
