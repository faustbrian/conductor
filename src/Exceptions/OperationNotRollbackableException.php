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
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationNotRollbackableException extends RuntimeException
{
    public static function doesNotImplementInterface(): self
    {
        return new self('Operation does not implement Rollbackable interface');
    }
}
