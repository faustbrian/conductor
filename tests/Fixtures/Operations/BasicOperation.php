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
 * @author Brian Faust <brian@cline.sh>
 */
final class BasicOperation implements Operation
{
    public static bool $executed = false;

    public static function reset(): void
    {
        self::$executed = false;
    }

    public function handle(): void
    {
        self::$executed = true;
    }
}
