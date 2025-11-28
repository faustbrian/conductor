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
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationNeverExecutedException extends RuntimeException
{
    public static function cannotRepeat(string $operationName): self
    {
        return new self(
            sprintf(
                "Operation '%s' has never been executed. Cannot use --repeat flag for operations that haven't run before.",
                $operationName,
            ),
        );
    }
}
