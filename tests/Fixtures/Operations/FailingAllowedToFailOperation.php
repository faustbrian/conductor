<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\AllowedToFail;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationFailedIntentionallyException;

/**
 * Operation that always fails but is allowed to fail
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FailingAllowedToFailOperation implements AllowedToFail, Operation
{
    public static int $handleCount = 0;

    public static function reset(): void
    {
        self::$handleCount = 0;
    }

    public function handle(): void
    {
        ++self::$handleCount;

        throw OperationFailedIntentionallyException::create();
    }
}
