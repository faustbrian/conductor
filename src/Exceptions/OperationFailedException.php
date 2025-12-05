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
 * Base exception for operation execution failures.
 *
 * Thrown when an operation encounters an unrecoverable error during execution.
 * Unlike OperationFailedIntentionallyException which is used for controlled testing
 * scenarios, this exception hierarchy represents genuine runtime errors such as API
 * failures, database constraint violations, or business logic violations.
 *
 * Consumers can catch this base class to handle any operation failure:
 *
 * ```php
 * try {
 *     $sequencer->execute();
 * } catch (OperationFailedException $e) {
 *     // Handle any operation failure
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @see OperationFailedIntentionallyException For intentional test failures
 */
abstract class OperationFailedException extends RuntimeException implements SequencerException
{
    // Abstract base - no factory methods
}
