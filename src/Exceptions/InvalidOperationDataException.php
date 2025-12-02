<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use LogicException;

/**
 * Thrown when operation data is malformed or missing required fields.
 *
 * This exception occurs when attempting to deserialize or reconstruct an operation
 * from stored data (database, cache, queue) but the data structure is invalid or
 * incomplete. Most commonly thrown when the operation class name is missing from
 * serialized operation data, preventing proper operation reconstruction.
 *
 * ```php
 * // Example scenario that would trigger this exception:
 * $operationData = [
 *     'id' => '123',
 *     'status' => 'pending',
 *     // Missing 'class' key - cannot determine which operation class to instantiate
 * ];
 *
 * try {
 *     $operation = Operation::fromData($operationData);
 * } catch (InvalidOperationDataException $e) {
 *     // Data is missing required 'class' field
 *     Log::error('Cannot reconstruct operation from invalid data', [
 *         'exception' => $e,
 *         'data' => $operationData,
 *     ]);
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidOperationDataException extends LogicException implements SequencerException
{
    /**
     * Create exception for operation data missing class identifier.
     *
     * Thrown when attempting to deserialize operation data that lacks the required
     * 'class' field. Without the class name, the system cannot determine which
     * operation class to instantiate, preventing proper operation reconstruction.
     *
     * @return self Exception instance with missing class error message
     */
    public static function missingClass(): self
    {
        return new self('Operation data missing class');
    }
}
