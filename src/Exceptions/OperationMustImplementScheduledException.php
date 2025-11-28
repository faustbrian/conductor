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
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationMustImplementScheduledException extends LogicException
{
    public static function forDispatch(): self
    {
        return new self('Operation must implement Scheduled interface');
    }
}
