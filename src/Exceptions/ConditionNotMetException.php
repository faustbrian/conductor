<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown when a required condition was not satisfied.
 *
 * Used when runtime validation determines that preconditions for execution
 * are not met. Unlike validation failures which represent errors, these are
 * legitimate reasons to skip processing (e.g., amount too small to process,
 * user opted out, feature disabled).
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ConditionNotMetException extends SkipOperationException
{
    /**
     * Create exception for unsatisfied condition.
     *
     * @param  string $condition Description of which condition was not met
     * @return self   Exception instance with condition details
     */
    public static function forCondition(string $condition): self
    {
        return new self('Condition not met: '.$condition);
    }
}
