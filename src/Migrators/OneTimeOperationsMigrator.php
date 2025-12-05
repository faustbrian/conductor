<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Migrators;

use Cline\Sequencer\Contracts\Migrator;
use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Exceptions\MissingOperationFieldException;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

use function is_string;
use function property_exists;
use function sprintf;
use function throw_if;
use function throw_unless;

/**
 * Migrator for importing operations from TimoKoerber/laravel-one-time-operations.
 *
 * This migrator reads operation execution records from the one_time_operations table
 * and imports them into Sequencer's operations table, preserving execution history
 * and preventing duplicate execution when transitioning to Sequencer.
 *
 * The source table structure from laravel-one-time-operations:
 * - id: bigint primary key
 * - name: string (operation class name)
 * - dispatched: enum('sync', 'async')
 * - processed_at: timestamp (nullable)
 *
 * These records are mapped to Sequencer's operations table:
 * - name: operation class name (preserved exactly)
 * - type: sync/async (mapped from dispatched column)
 * - executed_at: operation start time (from processed_at)
 * - completed_at: operation completion time (same as executed_at)
 * - state: 'completed' (all migrated operations are marked complete)
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OneTimeOperationsMigrator implements Migrator
{
    /**
     * Statistics tracking the migration process.
     *
     * @var array{operations: int, skipped: int, errors: array<string>}
     */
    private array $statistics = [
        'operations' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    /**
     * Create a new OneTimeOperations migrator instance.
     *
     * @param string      $sourceTable      The one_time_operations table name (default: 'one_time_operations')
     * @param null|string $sourceConnection The source database connection name (null for default)
     * @param null|string $targetConnection The target database connection name (null for default)
     */
    public function __construct(
        private readonly string $sourceTable = 'one_time_operations',
        private readonly ?string $sourceConnection = null,
        private readonly ?string $targetConnection = null,
    ) {}

    /**
     * Execute the migration from laravel-one-time-operations to Sequencer.
     *
     * Imports all operation records from the source table into Sequencer's operations
     * table. Only processes records that have been completed (processed_at is not null).
     * Skips operations that already exist in Sequencer to support idempotent migrations.
     */
    public function migrate(): void
    {
        $this->statistics = [
            'operations' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $operations = $this->fetchCompletedOperations();

            foreach ($operations as $operation) {
                try {
                    $this->migrateOperation($operation);
                } catch (Throwable $e) {
                    $operationName = property_exists($operation, 'name') && is_string($operation->name)
                        ? $operation->name
                        : 'unknown';
                    $this->statistics['errors'][] = sprintf("Failed to migrate operation '%s': %s", $operationName, $e->getMessage());
                }
            }
        } catch (Throwable $throwable) {
            $this->statistics['errors'][] = 'Migration failed: '.$throwable->getMessage();

            throw $throwable;
        }
    }

    /**
     * Retrieve migration statistics.
     *
     * @return array{operations: int, skipped: int, errors: array<string>} Migration statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Fetch all completed operations from laravel-one-time-operations table.
     *
     * Retrieves operation records that have been processed (processed_at is not null),
     * indicating they have completed execution and should be migrated to prevent
     * re-execution in Sequencer.
     *
     * @return array<int, stdClass> Array of operation records from source table
     */
    private function fetchCompletedOperations(): array
    {
        $query = $this->sourceConnection === null
            ? DB::table($this->sourceTable)
            : DB::connection($this->sourceConnection)->table($this->sourceTable);

        return $query
            ->whereNotNull('processed_at')
            ->oldest('processed_at')
            ->get()
            ->all();
    }

    /**
     * Migrate a single operation record to Sequencer.
     *
     * Creates a corresponding record in Sequencer's operations table if it doesn't
     * already exist. The operation is marked as completed with execution and completion
     * timestamps set to the processed_at time from the source record.
     *
     * @param stdClass $operation The source operation record
     *
     * @throws Throwable When migration fails for this operation
     */
    private function migrateOperation(stdClass $operation): void
    {
        // Validate required fields exist
        throw_if(!property_exists($operation, 'name') || !is_string($operation->name), MissingOperationFieldException::forField('name'));

        throw_if(!property_exists($operation, 'dispatched') || !is_string($operation->dispatched), MissingOperationFieldException::forField('dispatched'));

        throw_unless(property_exists($operation, 'processed_at'), MissingOperationFieldException::forField('processed_at'));

        $operationName = $operation->name;
        $dispatched = $operation->dispatched;
        $processedAt = $operation->processed_at;

        // Check if operation already exists in Sequencer
        if ($this->operationExists($operationName)) {
            ++$this->statistics['skipped'];

            return;
        }

        // Map dispatched type to Sequencer execution method
        $type = match ($dispatched) {
            'async' => ExecutionMethod::Async,
            'sync' => ExecutionMethod::Sync,
            default => ExecutionMethod::Sync,
        };

        // Create operation record using Eloquent model (handles ULID/UUID generation)
        $operation = new Operation();

        if ($this->targetConnection !== null) {
            $operation->setConnection($this->targetConnection);
        }

        $operation->fill([
            'name' => $operationName,
            'type' => $type->value,
            'executed_at' => $processedAt,
            'completed_at' => $processedAt,
            'state' => OperationState::Completed->value,
        ]);

        $operation->save();

        ++$this->statistics['operations'];
    }

    /**
     * Check if an operation already exists in Sequencer's operations table.
     *
     * Used for idempotent migrations - operations that have already been migrated
     * on a previous run are skipped to prevent duplicates.
     *
     * @param  string $operationName The operation class name to check
     * @return bool   True if operation exists, false otherwise
     */
    private function operationExists(string $operationName): bool
    {
        $query = $this->targetConnection !== null
            ? Operation::on($this->targetConnection)
            : Operation::query();

        return $query->where('name', $operationName)->exists();
    }
}
