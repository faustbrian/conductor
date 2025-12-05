<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown for testing custom logging channel routing.
 *
 * Used in test scenarios to verify that error notifications are properly
 * routed to custom logging channels or external monitoring systems.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CustomChannelOperationException extends OperationFailedException
{
    /**
     * Create exception for custom channel testing.
     *
     * @return self Exception instance for custom channel testing
     */
    public static function create(): self
    {
        return new self('Error with custom channel');
    }
}
