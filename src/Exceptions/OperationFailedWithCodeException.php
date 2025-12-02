<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown with a custom message and HTTP-style error code.
 *
 * Use when integrating with APIs or HTTP services where standard error codes
 * provide meaningful context (e.g., 400 for validation errors, 409 for conflicts,
 * 500 for server errors).
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationFailedWithCodeException extends OperationFailedException
{
    /**
     * Create exception with custom message and error code.
     *
     * @param  string $message Descriptive error message explaining the failure reason
     * @param  int    $code    HTTP-style error code or custom application error code
     * @return self   Exception instance with message and error code
     */
    public static function create(string $message, int $code): self
    {
        return new self($message, $code);
    }
}
