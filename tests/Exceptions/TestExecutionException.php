<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Exceptions;

use Exception;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class TestExecutionException extends Exception
{
    public static function shouldNotExecute(): self
    {
        return new self('Should not execute');
    }

    public static function shouldNotExecuteWhenFaking(): self
    {
        return new self('Should not execute when faking');
    }

    public static function secondOperationFails(): self
    {
        return new self('Second operation fails');
    }

    public static function rollbackFailed(): self
    {
        return new self('Rollback failed');
    }

    public static function exitCodeTest(): self
    {
        return new self('Exit code test');
    }
}
