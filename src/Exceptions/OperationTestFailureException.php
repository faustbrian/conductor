<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown in test suites to verify basic error handling and failure recovery.
 *
 * Used for generic test failure scenarios without needing detailed error
 * messages or codes.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationTestFailureException extends OperationFailedException
{
    /**
     * Create exception for generic test failure scenarios.
     *
     * @return self Exception instance with generic failure message
     */
    public static function create(): self
    {
        return new self('Operation failed');
    }
}
