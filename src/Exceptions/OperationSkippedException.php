<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown with a custom skip reason.
 *
 * Use when none of the predefined skip reasons match your use case.
 * The reason will be logged to help track why operations are being skipped.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationSkippedException extends SkipOperationException
{
    /**
     * Create exception with custom skip reason.
     *
     * @param  string $reason Descriptive explanation of why the operation is being skipped
     * @return self   Exception instance with custom skip reason
     */
    public static function withReason(string $reason = 'Operation skipped'): self
    {
        return new self($reason);
    }
}
