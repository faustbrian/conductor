<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Orchestrators;

use Cline\Sequencer\Contracts\AllowedToFail;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Exceptions\CannotAcquireLockException;
use Cline\Sequencer\Exceptions\InvalidOperationDataException;
use Cline\Sequencer\Exceptions\UnknownTaskTypeException;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Cline\Sequencer\Support\DependencyResolver;
use Cline\Sequencer\Support\MigrationDiscovery;
use Cline\Sequencer\Support\OperationDiscovery;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Throwable;

use function array_filter;
use function array_values;
use function base_path;
use function config;
use function str_replace;
use function usort;

/**
 * Batch orchestrator that allows individual operations to fail without affecting others.
 *
 * Executes operations in parallel using Laravel's batch system with fault tolerance
 * for operations implementing the AllowedToFail marker interface. Failed operations
 * implementing AllowedToFail are logged but do not cause the entire batch to fail
 * or trigger rollback of successful operations. Non-AllowedToFail operations will
 * still cause batch failure if they encounter errors.
 *
 * Use this orchestrator when you need parallel execution with graceful degradation
 * for non-critical operations. Ideal for scenarios like bulk data imports where
 * some records may fail validation but shouldn't block the entire import process.
 *
 * ```php
 * // Operations can opt into fault tolerance
 * final readonly class ImportUserData implements Operation, AllowedToFail
 * {
 *     public function handle(): void
 *     {
 *         // This operation can fail without affecting other imports
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class AllowedToFailBatchOrchestrator implements Orchestrator
{
    /**
     * Create a new allowed-to-fail batch orchestrator instance.
     *
     * @param OperationDiscovery $operationDiscovery Service for discovering pending operations
     *                                               from configured discovery paths in the application
     * @param MigrationDiscovery $migrationDiscovery Service for discovering pending Laravel migrations
     *                                               from registered migration directories
     * @param DependencyResolver $dependencyResolver Service for resolving operation dependencies and
     *                                               ensuring correct execution order based on declared
     *                                               dependencies between operations
     */
    public function __construct(
        private OperationDiscovery $operationDiscovery,
        private MigrationDiscovery $migrationDiscovery,
        private DependencyResolver $dependencyResolver,
    ) {}

    /**
     * Execute all pending migrations and operations with fault-tolerant batch processing.
     *
     * Migrations are executed sequentially first to maintain schema integrity, then all
     * operations are dispatched as a single Laravel batch for parallel execution. Operations
     * implementing AllowedToFail can fail without causing batch failure.
     *
     * @param bool        $isolate   Whether to use atomic lock for multi-server safety preventing
     *                               concurrent execution across distributed systems
     * @param bool        $dryRun    Preview execution plan without actually running tasks, returns
     *                               list of operations that would be executed
     * @param null|string $from      Resume execution from specific timestamp (YYYY_MM_DD_HHMMSS format)
     *                               skipping all operations before this timestamp
     * @param bool        $repeat    Re-execute already-completed operations, useful for testing or
     *                               reprocessing after configuration changes
     * @param bool        $forceSync Force synchronous execution (ignored for batch orchestrators)
     *
     * @throws Throwable
     *
     * @return null|list<array{type: string, timestamp: string, name: string}>
     */
    public function process(bool $isolate = false, bool $dryRun = false, ?string $from = null, bool $repeat = false, bool $forceSync = false): ?array
    {
        if ($dryRun) {
            return $this->preview($from, $repeat);
        }

        if ($isolate) {
            $this->processWithLock($from, $repeat);

            return null;
        }

        $this->execute($from, $repeat);

        return null;
    }

    /**
     * Preview pending tasks without executing them.
     *
     * Discovers all pending migrations and operations and returns their details for
     * inspection. Useful for validating the execution plan before committing to
     * running operations in production environments.
     *
     * @param  null|string                                                $from   Resume from specific timestamp, filtering out earlier tasks
     * @param  bool                                                       $repeat Include already-executed operations in the preview
     * @return list<array{type: string, timestamp: string, name: string}>
     */
    private function preview(?string $from = null, bool $repeat = false): array
    {
        $tasks = $this->discoverPendingTasks($repeat);

        if ($from) {
            $tasks = array_filter($tasks, fn (array $task): bool => $task['timestamp'] >= $from);
        }

        $preview = [];

        foreach ($tasks as $task) {
            /** @var array{path?: string, name: string, class?: string} $data */
            $data = $task['data'];

            $name = match ($task['type']) {
                'migration' => $data['name'],
                'operation' => $data['class'] ?? throw InvalidOperationDataException::missingClass(),
                default => throw UnknownTaskTypeException::forType($task['type']),
            };

            $preview[] = [
                'type' => $task['type'],
                'timestamp' => $task['timestamp'],
                'name' => $name,
            ];
        }

        return $preview;
    }

    /**
     * Execute with atomic lock to prevent concurrent execution.
     *
     * Acquires a distributed lock before processing to ensure only one instance
     * can execute operations at a time across multiple servers or processes. The
     * lock prevents race conditions and duplicate operation execution in clustered
     * deployments.
     *
     * @param null|string $from   Resume from specific timestamp
     * @param bool        $repeat Re-execute already-completed operations
     *
     * @throws CannotAcquireLockException When lock acquisition times out
     * @throws Throwable
     */
    private function processWithLock(?string $from = null, bool $repeat = false): void
    {
        /** @var string $lockStore */
        $lockStore = config('sequencer.execution.lock.store');

        /** @var int $timeout */
        $timeout = config('sequencer.execution.lock.timeout', 60);

        /** @var int $ttl */
        $ttl = config('sequencer.execution.lock.ttl', 600);

        // @phpstan-ignore-next-line
        $lock = Cache::store($lockStore)->lock('sequencer:process', $ttl);

        // @phpstan-ignore-next-line
        if (!$lock->block($timeout)) {
            throw CannotAcquireLockException::timeoutExceeded();
        }

        try {
            $this->execute($from, $repeat);
        } finally {
            // @phpstan-ignore-next-line
            $lock->release();
        }
    }

    /**
     * Discover and execute all pending tasks with fault-tolerant batch processing.
     *
     * Executes migrations sequentially first to ensure database schema is current,
     * then dispatches all operations as a single batch for parallel execution with
     * fault tolerance for AllowedToFail operations.
     *
     * @param null|string $from   Resume from specific timestamp
     * @param bool        $repeat Re-execute already-completed operations
     *
     * @throws Throwable
     */
    private function execute(?string $from = null, bool $repeat = false): void
    {
        $tasks = $this->discoverPendingTasks($repeat);

        if ($from) {
            $tasks = array_filter($tasks, fn (array $task): bool => $task['timestamp'] >= $from);
        }

        if ($tasks === []) {
            Event::dispatch(
                new NoPendingOperations(ExecutionMethod::AllowedToFailBatch),
            );

            return;
        }

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::AllowedToFailBatch),
        );

        $migrations = array_filter($tasks, fn (array $task): bool => $task['type'] === 'migration');
        $operations = array_filter($tasks, fn (array $task): bool => $task['type'] === 'operation');

        foreach ($migrations as $migration) {
            $this->executeMigration($migration);
        }

        if ($operations !== []) {
            /** @var list<array{type: string, timestamp: string, data: mixed, operation?: \Cline\Sequencer\Contracts\Operation}> $operationsList */
            $operationsList = array_values($operations);
            $this->executeBatch($operationsList);
        }

        Event::dispatch(
            new OperationsEnded(ExecutionMethod::AllowedToFailBatch),
        );
    }

    /**
     * Execute operations as a fault-tolerant Laravel batch.
     *
     * Creates database records for all operations, wraps them in ExecuteOperation jobs,
     * and dispatches as a batch with allowFailures() enabled. The batch callbacks
     * differentiate between AllowedToFail operations (logged as warnings) and critical
     * operations (logged as errors) based on interface implementation.
     *
     * @param list<array{type: string, timestamp: string, data: mixed, operation?: \Cline\Sequencer\Contracts\Operation}> $operations
     *
     * @throws Throwable
     *
     * @return Batch|BatchFake The dispatched batch instance for monitoring
     */
    private function executeBatch(array $operations): mixed
    {
        $jobs = [];
        $operationRecords = [];

        foreach ($operations as $task) {
            /** @var array{class: string, name: string} $operationData */
            $operationData = $task['data'];
            $operationPath = $operationData['class'];
            $operationName = $operationData['name'];

            /** @var \Cline\Sequencer\Contracts\Operation $operation */
            $operation = $task['operation'] ?? require $operationPath;

            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::AllowedToFailBatch,
                'state' => OperationState::Pending,
                'executed_at' => Date::now(),
            ]);

            /** @var int|string $recordId */
            $recordId = $record->id;
            $operationRecords[$recordId] = [
                'operation' => $operation,
                'record' => $record,
            ];

            $jobs[] = new ExecuteOperation($operation, $recordId);
        }

        return Bus::batch($jobs)
            ->name('Sequencer AllowedToFail Operations Batch')
            ->allowFailures()
            ->then(fn (Batch $batch) => $this->processBatchResults($batch, $operationRecords))
            ->catch(fn (Batch $batch) => $this->handleCriticalFailures($batch))
            ->dispatch();
    }

    /**
     * Process batch results and differentiate between allowed and critical failures.
     *
     * Iterates through all operation records to check their completion status. Operations
     * implementing AllowedToFail are logged as warnings when they fail, while operations
     * without this interface are logged as errors. This provides clear visibility into
     * which failures are expected and which require immediate attention.
     *
     * @param Batch                                                                                             $batch            The completed batch instance
     * @param array<int|string, array{operation: \Cline\Sequencer\Contracts\Operation, record: OperationModel}> $operationRecords Map of operation IDs to their instances and records
     */
    private function processBatchResults(Batch $batch, array $operationRecords): void
    {
        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');

        foreach ($operationRecords as $data) {
            $record = $data['record']->fresh();

            if ($record === null) {
                continue;
            }

            // Check if operation failed
            if ($record->state === OperationState::Failed) {
                $operation = $data['operation'];

                if ($operation instanceof AllowedToFail) {
                    Log::channel($logChannel)->warning('AllowedToFail operation failed (non-blocking)', [
                        'operation' => $record->name,
                        'batch_id' => $batch->id,
                    ]);
                } else {
                    Log::channel($logChannel)->error('Critical operation failed in AllowedToFail batch', [
                        'operation' => $record->name,
                        'batch_id' => $batch->id,
                    ]);
                }
            }
        }
    }

    /**
     * Handle critical batch failures requiring immediate attention.
     *
     * Logs a critical alert when the batch encounters failures, providing batch
     * statistics for troubleshooting. This callback is triggered by Laravel's batch
     * system when any job in the batch fails, regardless of AllowedToFail status.
     *
     * @param Batch $batch The failed batch instance
     */
    private function handleCriticalFailures(Batch $batch): void
    {
        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');

        Log::channel($logChannel)->critical('AllowedToFail batch encountered failures', [
            'batch_id' => $batch->id,
            'total_jobs' => $batch->totalJobs,
            'failed_jobs' => $batch->failedJobs,
        ]);
    }

    /**
     * Execute a single Laravel migration.
     *
     * Converts the absolute migration path to a relative path from Laravel's base
     * directory and executes it using the Artisan migrate command with force flag
     * to bypass production environment confirmation prompts.
     *
     * @param array{type: string, timestamp: string, data: mixed} $task Migration task containing path and metadata
     *
     * @throws Throwable
     */
    private function executeMigration(array $task): void
    {
        /** @var array{path: string, name: string} $migration */
        $migration = $task['data'];

        $relativePath = str_replace(base_path().'/', '', $migration['path']);

        Artisan::call('migrate', [
            '--path' => $relativePath,
            '--force' => true,
        ]);
    }

    /**
     * Discover all pending migrations and operations sorted by timestamp and dependencies.
     *
     * Combines pending migrations and operations into a unified task list, sorts them
     * chronologically by timestamp, then re-sorts to respect operation dependencies
     * ensuring dependent operations execute after their prerequisites.
     *
     * @param  bool                                                      $repeat Include already-executed operations for re-execution
     * @return list<array{type: string, timestamp: string, data: mixed}>
     */
    private function discoverPendingTasks(bool $repeat = false): array
    {
        $migrations = $this->migrationDiscovery->getPending();
        $operations = $this->operationDiscovery->getPending($repeat);

        $tasks = [];

        foreach ($migrations as $migration) {
            $tasks[] = [
                'type' => 'migration',
                'timestamp' => $migration['timestamp'],
                'data' => $migration,
            ];
        }

        foreach ($operations as $operation) {
            $tasks[] = [
                'type' => 'operation',
                'timestamp' => $operation['timestamp'],
                'data' => $operation,
            ];
        }

        usort($tasks, fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        return $this->dependencyResolver->sortByDependencies($tasks);
    }
}
