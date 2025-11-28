<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Exceptions;

use RuntimeException;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class SimulatedFailureException extends RuntimeException
{
    public static function shouldNeverExecute(): self
    {
        return new self('Should never execute');
    }

    public static function inDryRun(): self
    {
        return new self('Should not execute in dry-run');
    }

    public static function inPreview(): self
    {
        return new self('Should not execute in preview');
    }

    public static function operationFailed(): self
    {
        return new self('Operation failed');
    }

    public static function allowedToFail(): self
    {
        return new self('Allowed to fail - should not block batch');
    }
}
