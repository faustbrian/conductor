<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown with a custom error message explaining the failure reason.
 *
 * Use when you need to provide specific context about what went wrong
 * during operation execution.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationFailedWithMessageException extends OperationFailedException
{
    /**
     * Create exception with custom error message.
     *
     * @param  string $message Descriptive error message explaining the failure reason
     * @return self   Exception instance with provided error message
     */
    public static function create(string $message): self
    {
        return new self($message);
    }
}
