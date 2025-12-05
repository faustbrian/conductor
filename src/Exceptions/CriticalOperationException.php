<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown for critical system errors requiring immediate attention.
 *
 * Used for severe errors that may require manual intervention, such as data
 * corruption, critical resource unavailability, or system integrity violations.
 * Typically triggers high-severity logging and alerting.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CriticalOperationException extends OperationFailedException
{
    /**
     * Create exception for critical system errors.
     *
     * @return self Exception instance with critical error message
     */
    public static function create(): self
    {
        return new self('Critical error');
    }
}
