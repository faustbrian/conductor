<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown when target record already exists in the database.
 *
 * Used when attempting to create a record but discovering it already exists.
 * Often used after acquiring a lock to prevent race conditions in concurrent
 * operation execution.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class RecordAlreadyExistsException extends SkipOperationException
{
    /**
     * Create exception for existing record.
     *
     * @return self Exception instance with record exists message
     */
    public static function create(): self
    {
        return new self('Record already exists');
    }
}
