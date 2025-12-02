<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Support\Exceptions;

use Exception;

use function sprintf;

/**
 * Test-only exception for simulating failures in test scenarios.
 *
 * This exception is used in tests to verify error handling, rollback logic,
 * and failure recovery mechanisms without needing actual error conditions.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TestException extends Exception
{
    /**
     * Create exception for simulated failure.
     *
     * @param  string $reason The reason for the simulated failure
     * @return self   Exception instance with failure reason
     */
    public static function simulatedFailure(string $reason = 'Simulated failure'): self
    {
        return new self($reason);
    }

    /**
     * Create exception for dry-run execution violation.
     *
     * @param  string $operation Description of what should not have executed
     * @return self   Exception instance with dry-run violation message
     */
    public static function shouldNotExecuteInDryRun(string $operation = 'execute'): self
    {
        return new self(sprintf('Should not %s in dry-run', $operation));
    }

    /**
     * Create exception for unexpected runtime error.
     *
     * @param  string $message The error message
     * @return self   Exception instance with error message
     */
    public static function runtimeError(string $message): self
    {
        return new self($message);
    }

    /**
     * Create exception for rollback failure.
     *
     * @return self Exception instance for rollback failure
     */
    public static function rollbackFailed(): self
    {
        return new self('Rollback failed');
    }

    /**
     * Create exception for expected migration failure.
     *
     * @return self Exception instance for expected migration failure
     */
    public static function expectedMigrationFailure(): self
    {
        return new self('Expected migration to fail');
    }

    /**
     * Create exception for dependency execution order violation.
     *
     * @param  string $dependency The dependency that should have executed first
     * @param  string $current    The current operation
     * @return self   Exception instance with dependency violation message
     */
    public static function dependencyNotExecuted(string $dependency, string $current): self
    {
        return new self(sprintf('%s did not execute before %s', $dependency, $current));
    }

    /**
     * Create exception for schema check failure.
     *
     * @param  string $tableName The table that should exist
     * @return self   Exception instance with schema check failure message
     */
    public static function migrationNotRun(string $tableName): self
    {
        return new self('Migration did not run before operation - missing table: '.$tableName);
    }
}
