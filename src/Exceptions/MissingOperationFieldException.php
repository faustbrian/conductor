<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * Thrown when an operation record is missing a required field.
 *
 * This exception occurs during operation data validation when a required
 * field is not present on the operation record, typically during migration
 * from external sources.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MissingOperationFieldException extends RuntimeException implements SequencerException
{
    /**
     * Create exception for missing field.
     *
     * @param  string $field The name of the missing field
     * @return self   Exception instance with field name in error message
     */
    public static function forField(string $field): self
    {
        return new self(
            sprintf('Operation record missing required "%s" field', $field),
        );
    }
}
