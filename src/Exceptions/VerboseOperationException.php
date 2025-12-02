<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown for testing verbose exception logging and reporting.
 *
 * Used to verify that detailed exception information (stack traces, context,
 * etc.) is properly captured and reported in verbose logging modes.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class VerboseOperationException extends OperationFailedException
{
    /**
     * Create exception for verbose logging testing.
     *
     * @return self Exception instance for verbose logging testing
     */
    public static function create(): self
    {
        return new self('Verbose exception test');
    }
}
