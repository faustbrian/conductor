<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown for testing default logging channel behavior.
 *
 * Used in test scenarios to verify that errors are properly logged to the
 * default application logging channel when no custom channel is specified.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class DefaultChannelOperationException extends OperationFailedException
{
    /**
     * Create exception for default channel testing.
     *
     * @return self Exception instance for default channel testing
     */
    public static function create(): self
    {
        return new self('Default channel error');
    }
}
