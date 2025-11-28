<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\Operation;

/**
 * Operation that does NOT implement Rollbackable
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class NonRollbackableOperation implements Operation
{
    public static int $handleCount = 0;

    public static function reset(): void
    {
        self::$handleCount = 0;
    }

    public function handle(): void
    {
        ++self::$handleCount;
    }
}
