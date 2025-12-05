<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown for code coverage testing purposes.
 *
 * Used to ensure error handling code paths are exercised during test suite
 * execution, improving code coverage metrics and verifying error handling works.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CoverageOperationException extends OperationFailedException
{
    /**
     * Create exception for coverage testing.
     *
     * @return self Exception instance for coverage testing
     */
    public static function create(): self
    {
        return new self('Test exception for coverage');
    }
}
