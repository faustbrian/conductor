<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Exceptions\OperationNeverExecutedException;
use Illuminate\Support\Facades\File;

use function config;
use function in_array;
use function preg_match;

/**
 * Discovers pending operations for Sequencer's execution pipeline.
 *
 * Scans configured discovery paths for operation files and compares them against
 * the operations table to identify pending executions. Supports both normal mode
 * (execute only new operations) and repeat mode (re-execute previously completed
 * operations). Operations follow timestamp-based naming for chronological ordering.
 *
 * ```php
 * $discovery = new OperationDiscovery();
 * $pending = $discovery->getPending();
 *
 * foreach ($pending as $operation) {
 *     echo "{$operation['timestamp']}: {$operation['name']}\n";
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationDiscovery
{
    /**
     * Get all pending operations that haven't been executed.
     *
     * Normal mode: returns only operations that have never been completed.
     * Repeat mode: returns all discovered operations but throws exception if any
     * have never been executed, ensuring only previously-run operations are repeated.
     *
     * @param bool $repeat If true, include completed operations for re-execution but validate
     *                     all discovered operations have been executed at least once
     *
     * @throws OperationNeverExecutedException When repeat mode encounters never-executed operations
     *
     * @return list<array{class: string, name: string, timestamp: string, path: string}>
     *                                                                                   Pending operations with file path (stored in 'class' key for legacy reasons),
     *                                                                                   filename, extracted timestamp (YYYY_MM_DD_HHMMSS), and absolute path
     */
    public function getPending(bool $repeat = false): array
    {
        $discovered = $this->discoverOperationFiles();

        if ($repeat) {
            $executed = $this->getExecutedOperations();

            // In repeat mode, throw if any discovered operation has never been executed
            foreach ($discovered as $operation) {
                if (!in_array($operation['name'], $executed, true)) {
                    throw OperationNeverExecutedException::cannotRepeat($operation['name']);
                }
            }

            return $discovered;
        }

        $executed = $this->getExecutedOperations();
        $pending = [];

        foreach ($discovered as $operation) {
            if (!in_array($operation['name'], $executed, true)) {
                $pending[] = $operation;
            }
        }

        return $pending;
    }

    /**
     * Get list of already-executed operation names.
     *
     * Queries the operations table for completed operations (completed_at is not null).
     * Failed or pending operations are excluded, allowing retry of incomplete executions.
     *
     * @return list<string> Operation filenames that have been successfully completed
     */
    private function getExecutedOperations(): array
    {
        /** @var list<string> */
        return Operation::query()
            ->whereNotNull('completed_at')
            ->pluck('name')
            ->all();
    }

    /**
     * Discover all operation files in configured paths.
     *
     * Scans configured discovery paths for PHP files matching the operation naming
     * convention (YYYY_MM_DD_HHMMSS_OperationName.php). Extracts timestamp from
     * filename for chronological ordering with migrations.
     *
     * @return list<array{class: string, name: string, timestamp: string, path: string}>
     *                                                                                   Discovered operations with file path (in 'class' key), filename, extracted
     *                                                                                   timestamp, and absolute path. Files not matching naming pattern are skipped.
     */
    private function discoverOperationFiles(): array
    {
        /** @var array<string> $paths */
        $paths = config('sequencer.execution.discovery_paths', []);
        $operations = [];

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            $files = File::files($path);

            foreach ($files as $file) {
                $filename = $file->getFilename();

                // Match pattern: YYYY_MM_DD_HHMMSS_OperationName.php
                if (!preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_(.+)\.php$/', $filename, $matches)) {
                    continue;
                }

                $timestamp = $matches[1];

                $operations[] = [
                    'class' => $file->getPathname(), // Store path to require the file later
                    'name' => $filename,
                    'timestamp' => $timestamp,
                    'path' => $file->getPathname(),
                ];
            }
        }

        return $operations;
    }
}
