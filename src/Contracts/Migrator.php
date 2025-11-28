<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for migrating operations from external operation management systems.
 *
 * Migrators handle the import of operation execution records from third-party operation
 * management packages into the Sequencer system. This enables seamless transitions between
 * operation orchestration providers while preserving existing execution history and preventing
 * duplicate execution of already-processed operations.
 *
 * The migration process typically involves:
 * - Reading operation execution records from source system's database tables
 * - Mapping external operation names to Sequencer's operation naming convention
 * - Converting execution timestamps and status information
 * - Creating corresponding records in Sequencer's operations table
 * - Preserving execution history for audit and rollback purposes
 *
 * Implementations should be idempotent where possible, allowing migrations to be
 * safely re-run without duplicating data or causing inconsistencies. Already-migrated
 * operations should be skipped on subsequent runs.
 *
 * ```php
 * // Migrate from TimoKoerber/laravel-one-time-operations
 * $migrator = new OneTimeOperationsMigrator(
 *     sourceTable: 'one_time_operations',
 *     sourceConnection: 'mysql'
 * );
 * $migrator->migrate();
 *
 * // Review migration results
 * $stats = $migrator->getStatistics();
 * echo "Migrated {$stats['operations']} operations";
 *
 * if (!empty($stats['errors'])) {
 *     Log::warning('Migration errors:', $stats['errors']);
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Migrator
{
    /**
     * Migrate all operation execution records from the source system.
     *
     * Imports operation execution history from an external operation management
     * system into Sequencer's operations table. This includes operation names,
     * execution timestamps, dispatch methods (sync/async), and completion status.
     * The migration preserves execution history to prevent operations from being
     * re-executed when transitioning to Sequencer.
     *
     * The migration should handle edge cases such as:
     * - Missing or corrupted source records
     * - Invalid operation name formats
     * - Duplicate operation entries
     * - Schema mismatches between source and destination tables
     *
     * These issues should be logged via getStatistics() rather than causing
     * complete migration failure, allowing partial success when possible.
     */
    public function migrate(): void;

    /**
     * Retrieve migration statistics and results.
     *
     * Returns comprehensive information about the migration process including
     * success counts, failure reasons, and any warnings or errors encountered.
     * This data is essential for validating the migration completed successfully
     * and identifying any issues that require manual intervention.
     *
     * The returned array contains:
     * - operations: Total number of operations successfully migrated
     * - skipped: Number of operations already present in Sequencer (idempotency)
     * - errors: Array of error messages for failed migrations or data inconsistencies
     *
     * @return array{operations: int, skipped: int, errors: array<int, string>} Migration statistics with counts and error details
     */
    public function getStatistics(): array;
}
