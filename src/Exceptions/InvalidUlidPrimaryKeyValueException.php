<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use function gettype;
use function sprintf;

/**
 * Thrown when assigning a non-string value to a ULID primary key.
 *
 * This exception occurs when manually setting ULID primary key values with
 * non-string types, which would violate database schema constraints.
 *
 * ```php
 * $model = new OrderModel();
 * try {
 *     $model->id = ['ulid' => '01ARYZ6S41']; // Assigning array to ULID field
 * } catch (InvalidUlidPrimaryKeyValueException $e) {
 *     // Exception: Cannot assign non-string value to ULID primary key. Got: array
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidUlidPrimaryKeyValueException extends InvalidPrimaryKeyValueException
{
    /**
     * Create exception for non-string value assigned to ULID primary key.
     *
     * @param  mixed $value The invalid value that was provided (will be type-checked)
     * @return self  Exception instance with descriptive error message including the actual type
     */
    public static function fromValue(mixed $value): self
    {
        return new self(
            sprintf(
                'Cannot assign non-string value to ULID primary key. Got: %s',
                gettype($value),
            ),
        );
    }
}
