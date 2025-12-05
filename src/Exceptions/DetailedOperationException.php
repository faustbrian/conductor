<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown with detailed error information and HTTP 500 code.
 *
 * Used for server-side errors that require detailed logging and investigation.
 * The 500 code indicates an internal error that is not the client's fault.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class DetailedOperationException extends OperationFailedException
{
    /**
     * Create exception with detailed error information.
     *
     * @return self Exception instance with detailed error message and code 500
     */
    public static function create(): self
    {
        return new self('Detailed error', 500);
    }
}
