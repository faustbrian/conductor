<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\ConditionalExecution;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class ConditionalOperation implements ConditionalExecution
{
    public static bool $executed = false;

    public static bool $shouldRunValue = true;

    public static function reset(): void
    {
        self::$executed = false;
        self::$shouldRunValue = true;
    }

    public function handle(): void
    {
        self::$executed = true;
    }

    public function shouldRun(): bool
    {
        return self::$shouldRunValue;
    }
}
