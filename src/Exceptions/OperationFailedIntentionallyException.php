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
 * Thrown when an operation deliberately fails for testing or control flow purposes.
 *
 * This exception is specifically designed for controlled failure scenarios, primarily
 * in test environments. Unlike OperationFailedException which represents genuine errors,
 * this exception is thrown intentionally to verify that error handling, rollback logic,
 * logging, and notification systems function correctly under failure conditions.
 *
 * Use this exception to test failure recovery paths without needing to trigger actual
 * error conditions like database failures or external service timeouts.
 *
 * ```php
 * // Example usage in tests:
 * it('rolls back database changes when operation fails', function () {
 *     $operation = new CreateUserOperation();
 *
 *     // Override operation to throw intentional failure
 *     $operation->shouldFail = true;
 *
 *     try {
 *         $operation->handle();
 *     } catch (OperationFailedIntentionallyException $e) {
 *         // Expected - verify rollback occurred
 *         expect(User::count())->toBe(0);
 *     }
 * });
 *
 * // Example in operation code:
 * class TestableOperation extends Operation
 * {
 *     public bool $shouldFail = false;
 *
 *     public function handle(): void
 *     {
 *         if ($this->shouldFail) {
 *             throw OperationFailedIntentionallyException::create();
 *         }
 *
 *         // Normal operation logic...
 *     }
 * }
 * ```
 *
 * @see OperationFailedException For genuine runtime errors
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationFailedIntentionallyException extends RuntimeException
{
    /**
     * Create exception for intentional operation failure.
     *
     * Throws a controlled failure exception to test error handling mechanisms.
     * This allows verification of rollback behavior, error logging, notification
     * systems, and failure recovery paths without requiring actual error conditions.
     *
     * @return self Exception instance with intentional failure message
     */
    public static function create(): self
    {
        return new self('Operation failed intentionally');
    }
}
