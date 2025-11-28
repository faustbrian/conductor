<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use InvalidArgumentException;

use function gettype;
use function sprintf;

/**
 * Thrown when attempting to assign invalid value types to string-based primary keys.
 *
 * This exception occurs when manually setting UUID or ULID primary key values with
 * non-string types, which would violate database schema constraints and cause runtime
 * errors. Ensures type safety for string-based unique identifiers.
 *
 * ```php
 * // Example scenarios that would trigger these exceptions:
 *
 * // UUID primary key violation
 * $model = new UserModel();
 * try {
 *     $model->id = 12345; // Assigning integer to UUID field
 * } catch (InvalidPrimaryKeyValueException $e) {
 *     // Exception: Cannot assign non-string value to UUID primary key. Got: integer
 * }
 *
 * // ULID primary key violation
 * $model = new OrderModel();
 * try {
 *     $model->id = ['ulid' => '01ARYZ6S41']; // Assigning array to ULID field
 * } catch (InvalidPrimaryKeyValueException $e) {
 *     // Exception: Cannot assign non-string value to ULID primary key. Got: array
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidPrimaryKeyValueException extends InvalidArgumentException
{
    /**
     * Create exception for non-string value assigned to UUID primary key.
     *
     * Thrown when attempting to manually set a UUID primary key with a value that
     * is not a string. The actual type of the invalid value is included in the error
     * message to assist debugging.
     *
     * @param  mixed $value The invalid value that was provided (will be type-checked)
     * @return self  Exception instance with descriptive error message including the actual type
     */
    public static function nonStringUuid(mixed $value): self
    {
        return new self(
            sprintf(
                'Cannot assign non-string value to UUID primary key. Got: %s',
                gettype($value),
            ),
        );
    }

    /**
     * Create exception for non-string value assigned to ULID primary key.
     *
     * Thrown when attempting to manually set a ULID primary key with a value that
     * is not a string. The actual type of the invalid value is included in the error
     * message to assist debugging.
     *
     * @param  mixed $value The invalid value that was provided (will be type-checked)
     * @return self  Exception instance with descriptive error message including the actual type
     */
    public static function nonStringUlid(mixed $value): self
    {
        return new self(
            sprintf(
                'Cannot assign non-string value to ULID primary key. Got: %s',
                gettype($value),
            ),
        );
    }
}
