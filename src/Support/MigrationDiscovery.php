<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;

use function in_array;
use function preg_match;

/**
 * Discovers pending migrations for Sequencer's unified execution pipeline.
 *
 * Scans configured migration paths and compares against Laravel's migration
 * repository to identify migrations that need execution. Provides migration
 * metadata including timestamps for chronological ordering alongside operations.
 *
 * ```php
 * $discovery = new MigrationDiscovery($migrator);
 * $pending = $discovery->getPending();
 *
 * foreach ($pending as $migration) {
 *     echo "{$migration['timestamp']}: {$migration['name']}\n";
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class MigrationDiscovery
{
    /**
     * Create a new migration discovery instance.
     *
     * @param Migrator $migrator Laravel's migration system used to access configured migration
     *                           paths, retrieve migration files from the filesystem, and query
     *                           the migrations table to determine which migrations have already
     *                           been executed. Essential for identifying pending migrations.
     */
    public function __construct(
        private Migrator $migrator,
    ) {}

    /**
     * Get all pending migrations that haven't been executed.
     *
     * Scans configured paths for migration files, filters out already-executed
     * migrations using Laravel's repository, and extracts timestamps for ordering.
     * Only returns migrations following Laravel's naming convention with timestamps.
     *
     * @return list<array{name: string, timestamp: string, path: string}>
     *                                                                    Pending migrations with filename, extracted timestamp (YYYY_MM_DD_HHMMSS),
     *                                                                    and absolute file path for execution
     */
    public function getPending(): array
    {
        $migrations = [];
        $paths = $this->migrator->paths();

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            $files = $this->migrator->getMigrationFiles($path);
            $ran = $this->migrator->getRepository()->getRan();

            foreach ($files as $file => $filePath) {
                if (in_array($file, $ran, true)) {
                    continue;
                }

                // Extract timestamp from filename: YYYY_MM_DD_HHMMSS_name.php
                if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $file, $matches)) {
                    $timestamp = $matches[1];

                    $migrations[] = [
                        'name' => $file,
                        'timestamp' => $timestamp,
                        'path' => $filePath,
                    ];
                }
            }
        }

        return $migrations;
    }
}
