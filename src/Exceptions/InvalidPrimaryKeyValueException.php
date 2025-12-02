<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use InvalidArgumentException;

/**
 * Base exception for invalid primary key value errors.
 *
 * Thrown when attempting to assign invalid value types to string-based primary keys.
 * Concrete exceptions exist for specific primary key types (UUID, ULID).
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidPrimaryKeyValueException extends InvalidArgumentException implements SequencerException
{
    // Abstract base - no factory methods
}
