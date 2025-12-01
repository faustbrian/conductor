<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\Idempotent;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class IdempotentOperation implements Idempotent
{
    public static int $executionCount = 0;

    public static function reset(): void
    {
        self::$executionCount = 0;
    }

    public function handle(): void
    {
        ++self::$executionCount;
    }
}
