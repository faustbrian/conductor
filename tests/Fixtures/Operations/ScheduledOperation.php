<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Scheduled;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class ScheduledOperation implements Operation, Scheduled
{
    public static bool $executed = false;

    public static ?DateTimeInterface $executeAtValue = null;

    public static function reset(): void
    {
        self::$executed = false;
        self::$executeAtValue = null;
    }

    public function handle(): void
    {
        self::$executed = true;
    }

    public function executeAt(): DateTimeInterface
    {
        return self::$executeAtValue ?? Date::now()->addMinutes(5);
    }
}
