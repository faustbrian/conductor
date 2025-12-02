<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown when work has already been completed.
 *
 * Used when an idempotent operation detects that the work it was going to
 * perform has already been done. Common in scenarios like payment processing,
 * data imports, or notification sending where duplicate execution must be avoided.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationAlreadyProcessedException extends SkipOperationException
{
    /**
     * Create exception for already processed operation.
     *
     * @return self Exception instance with already processed message
     */
    public static function create(): self
    {
        return new self('Operation already processed');
    }
}
