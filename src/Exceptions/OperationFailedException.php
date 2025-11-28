<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation encounters an unrecoverable error during execution.
 *
 * This is the primary exception for operation execution failures. Unlike
 * OperationFailedIntentionallyException which is used for controlled testing scenarios,
 * this exception represents genuine runtime errors such as API failures, database
 * constraint violations, external service timeouts, or business logic violations.
 *
 * When thrown, the operation is marked as failed, rolled back if transactional,
 * and logged with appropriate severity based on the error type. The exception
 * supports custom messages and error codes for detailed error reporting.
 *
 * ```php
 * // Example usage in an operation:
 * class CreateUserOperation extends Operation
 * {
 *     public function handle(): void
 *     {
 *         if ($this->email === null) {
 *             throw OperationFailedException::withMessage('Email is required');
 *         }
 *
 *         try {
 *             $this->user = User::create(['email' => $this->email]);
 *         } catch (UniqueConstraintViolation $e) {
 *             throw OperationFailedException::withMessageAndCode(
 *                 'User with this email already exists',
 *                 409
 *             );
 *         }
 *     }
 * }
 *
 * // Handling operation failures:
 * try {
 *     $sequencer->execute();
 * } catch (OperationFailedException $e) {
 *     Log::error('Operation failed', [
 *         'message' => $e->getMessage(),
 *         'code' => $e->getCode(),
 *     ]);
 * }
 * ```
 *
 * @see OperationFailedIntentionallyException For intentional test failures
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationFailedException extends RuntimeException
{
    /**
     * Create exception for generic test failure scenarios.
     *
     * Used in test suites to verify basic error handling and failure recovery
     * mechanisms without needing detailed error messages or codes.
     *
     * @return self Exception instance with generic failure message
     */
    public static function testFailure(): self
    {
        return new self('Operation failed');
    }

    /**
     * Create exception with custom error message.
     *
     * Use this factory method when you need to provide specific context about
     * what went wrong during operation execution. The message should describe
     * the error condition in business terms.
     *
     * @param  string $message Descriptive error message explaining the failure reason
     * @return self   Exception instance with provided error message
     */
    public static function withMessage(string $message): self
    {
        return new self($message);
    }

    /**
     * Create exception with custom message and HTTP-style error code.
     *
     * Use when integrating with APIs or HTTP services where standard error codes
     * provide meaningful context (e.g., 400 for validation errors, 409 for conflicts,
     * 500 for server errors). The code can be used for error categorization and routing.
     *
     * @param  string $message Descriptive error message explaining the failure reason
     * @param  int    $code    HTTP-style error code or custom application error code
     * @return self   Exception instance with message and error code
     */
    public static function withMessageAndCode(string $message, int $code): self
    {
        return new self($message, $code);
    }

    /**
     * Create exception to trigger database transaction rollback.
     *
     * Used in transactional operations to signal that all database changes within
     * the current transaction should be rolled back. Ensures data consistency when
     * partial operation completion would leave the system in an invalid state.
     *
     * @return self Exception instance with transaction rollback message
     */
    public static function transactionShouldRollback(): self
    {
        return new self('Transaction should rollback');
    }

    /**
     * Create exception for critical system errors requiring immediate attention.
     *
     * Used for severe errors that may require manual intervention, such as data
     * corruption, critical resource unavailability, or system integrity violations.
     * Typically triggers high-severity logging and alerting.
     *
     * @return self Exception instance with critical error message
     */
    public static function criticalError(): self
    {
        return new self('Critical error');
    }

    /**
     * Create exception with detailed error information and HTTP 500 code.
     *
     * Used for server-side errors that require detailed logging and investigation.
     * The 500 code indicates an internal error that is not the client's fault.
     *
     * @return self Exception instance with detailed error message and code 500
     */
    public static function detailedError(): self
    {
        return new self('Detailed error', 500);
    }

    /**
     * Create exception for testing custom logging channel routing.
     *
     * Used in test scenarios to verify that error notifications are properly
     * routed to custom logging channels or external monitoring systems.
     *
     * @return self Exception instance for custom channel testing
     */
    public static function withCustomChannel(): self
    {
        return new self('Error with custom channel');
    }

    /**
     * Create exception for testing default logging channel behavior.
     *
     * Used in test scenarios to verify that errors are properly logged to the
     * default application logging channel when no custom channel is specified.
     *
     * @return self Exception instance for default channel testing
     */
    public static function onDefaultChannel(): self
    {
        return new self('Default channel error');
    }

    /**
     * Create exception for code coverage testing purposes.
     *
     * Used to ensure error handling code paths are exercised during test suite
     * execution, improving code coverage metrics and verifying error handling works.
     *
     * @return self Exception instance for coverage testing
     */
    public static function forCoverage(): self
    {
        return new self('Test exception for coverage');
    }

    /**
     * Create exception for testing verbose exception logging and reporting.
     *
     * Used to verify that detailed exception information (stack traces, context,
     * etc.) is properly captured and reported in verbose logging modes.
     *
     * @return self Exception instance for verbose logging testing
     */
    public static function verboseExceptionTest(): self
    {
        return new self('Verbose exception test');
    }
}
