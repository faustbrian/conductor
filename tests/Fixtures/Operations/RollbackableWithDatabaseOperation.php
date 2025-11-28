<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Rollbackable;
use Illuminate\Support\Facades\DB;

use function now;

/**
 * Rollbackable operation that performs real database operations
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class RollbackableWithDatabaseOperation implements Operation, Rollbackable
{
    public static int $handleCount = 0;

    public static int $rollbackCount = 0;

    public static function reset(): void
    {
        self::$handleCount = 0;
        self::$rollbackCount = 0;
    }

    public function handle(): void
    {
        ++self::$handleCount;

        // Insert a test record in operations table
        DB::table('operations')->insert([
            'name' => 'rollbackable_test_'.self::$handleCount,
            'type' => 'test',
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function rollback(): void
    {
        ++self::$rollbackCount;

        // Delete the test record
        DB::table('operations')
            ->where('name', 'rollbackable_test_'.self::$handleCount)
            ->delete();
    }
}
