<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\Rollbackable;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class RollbackableOperation implements Rollbackable
{
    public static bool $executed = false;

    public static bool $rolledBack = false;

    public static function reset(): void
    {
        self::$executed = false;
        self::$rolledBack = false;
    }

    public function handle(): void
    {
        self::$executed = true;
    }

    public function rollback(): void
    {
        self::$rolledBack = true;
        self::$executed = false;
    }
}
