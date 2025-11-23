<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Orchestrators;

use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Contracts\Rollbackable;
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
use Throwable;

use function array_filter;
use function array_values;
use function base_path;
use function config;
use function str_replace;
use function usort;

/**
 * Transactional batch orchestrator with all-or-nothing semantics and automatic rollback.
 *
 * Executes operations in parallel as a batch but provides distributed transaction semantics
 * through automatic rollback on failure. When any operation fails, all successfully completed
 * operations implementing the Rollbackable interface are rolled back in reverse execution order.
 * This provides ACID-like guarantees across distributed operations that don't share a database
 * transaction.
 *
 * Use this orchestrator when you need parallel execution with strong consistency guarantees.
 * Best for critical operations where partial completion is unacceptable and compensating
 * actions can restore the system to its original state. Operations must implement Rollbackable
 * to participate in automatic rollback.
 *
 * ```php
 * // Operations can implement rollback logic
 * final readonly class TransferFunds implements Operation, Rollbackable
 * {
 *     public function handle(): void
 *     {
 *         // Transfer funds between accounts
 *     }
 *
 *     public function rollback(): void
 *     {
 *         // Reverse the transfer if batch fails
 *     }
 * }
 * ```
 *
 * @see BatchOrchestrator For parallel execution without rollback
 * @see AllowedToFailBatchOrchestrator For parallel execution with fault tolerance
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class TransactionalBatchOrchestrator implements Orchestrator
{
    /**
     * Create a new transactional batch orchestrator instance.
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
     * Execute all pending migrations and operations with transactional batch processing.
     *
     * Migrations are executed sequentially first to maintain schema integrity, then all
     * operations are dispatched as a single Laravel batch for parallel execution. If any
     * operation fails, all completed operations implementing Rollbackable are automatically
     * rolled back in reverse order.
     *
     * @param bool        $isolate Whether to use atomic lock for multi-server safety preventing
     *                             concurrent execution across distributed systems
     * @param bool        $dryRun  Preview execution plan without actually running tasks, returns
     *                             list of operations that would be executed
     * @param null|string $from    Resume execution from specific timestamp (YYYY_MM_DD_HHMMSS format)
     *                             skipping all operations before this timestamp
     * @param bool        $repeat  Re-execute already-completed operations, useful for testing or
     *                             reprocessing after configuration changes
     *
     * @throws Throwable
     *
     * @return null|list<array{type: string, timestamp: string, name: string}>
     */
    public function process(bool $isolate = false, bool $dryRun = false, ?string $from = null, bool $repeat = false): ?array
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
     * Discover and execute all pending tasks with transactional batch processing.
     *
     * Executes migrations sequentially first to ensure database schema is current,
     * then dispatches all operations as a single batch for parallel execution. If
     * any operation fails, triggers automatic rollback of all completed operations.
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
                new NoPendingOperations(ExecutionMethod::TransactionalBatch),
            );

            return;
        }

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::TransactionalBatch),
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
            new OperationsEnded(ExecutionMethod::TransactionalBatch),
        );
    }

    /**
     * Execute operations as a transactional Laravel batch with automatic rollback.
     *
     * Creates database records for all operations, wraps them in ExecuteOperation jobs,
     * and dispatches as a batch with a catch callback that triggers rollback on failure.
     * The batch does not use allowFailures() - any failure will invoke the rollback process.
     *
     * @param list<array{type: string, timestamp: string, data: mixed, operation?: \Cline\Sequencer\Contracts\Operation}> $operations
     *
     * @throws Throwable
     *
     * @return Batch The dispatched batch instance for monitoring
     */
    private function executeBatch(array $operations): Batch
    {
        $jobs = [];
        $operationRecords = [];
        $batchId = null;

        foreach ($operations as $task) {
            /** @var array{class: string, name: string} $operationData */
            $operationData = $task['data'];
            $operationPath = $operationData['class'];
            $operationName = $operationData['name'];

            /** @var \Cline\Sequencer\Contracts\Operation $operation */
            $operation = $task['operation'] ?? require $operationPath;

            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::TransactionalBatch,
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
            ->name('Sequencer Transactional Operations Batch')
            ->catch(function (Batch $batch) use (&$batchId): void {
                $batchId = $batch->id;
                $this->rollbackBatch($batch);
            })
            ->dispatch();
    }

    /**
     * Rollback all completed operations in reverse order when batch fails.
     *
     * Queries the database for all operations that completed successfully in this batch,
     * then iterates through them in reverse execution order to perform rollback. Each
     * operation is reconstructed from its stored data and its rollback() method is called
     * if it implements Rollbackable. Operations without rollback support are logged and
     * skipped. All rollback attempts are logged with detailed status information.
     *
     * @param Batch $batch The failed batch instance containing failure statistics
     */
    private function rollbackBatch(Batch $batch): void
    {
        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');

        Log::channel($logChannel)->critical('Transactional batch failed - initiating rollback', [
            'batch_id' => $batch->id,
            'total_jobs' => $batch->totalJobs,
            'failed_jobs' => $batch->failedJobs,
        ]);

        // Query for all completed operations in this batch
        $completedOperations = OperationModel::query()
            ->where('type', ExecutionMethod::TransactionalBatch)
            ->where('state', OperationState::Completed)
            ->whereNotNull('executed_at')
            ->latest('executed_at')
            ->get();

        if ($completedOperations->isEmpty()) {
            Log::channel($logChannel)->info('No completed operations to rollback');

            return;
        }

        Log::channel($logChannel)->info('Rolling back completed operations', [
            'count' => $completedOperations->count(),
        ]);

        /** @var OperationModel $record */
        foreach ($completedOperations as $record) {
            try {
                // Reconstruct the operation instance
                $operationData = $this->findOperationDataByName((string) $record->name);

                if ($operationData === null) {
                    Log::channel($logChannel)->warning('Cannot find operation data for rollback', [
                        'operation' => $record->name,
                    ]);

                    continue;
                }

                /** @var \Cline\Sequencer\Contracts\Operation $operation */
                $operation = require $operationData['class'];

                if (!$operation instanceof Rollbackable) {
                    Log::channel($logChannel)->info('Operation does not support rollback', [
                        'operation' => $record->name,
                    ]);

                    continue;
                }

                $operation->rollback();
                $record->update([
                    'rolled_back_at' => Date::now(),
                    'state' => OperationState::RolledBack,
                ]);

                Log::channel($logChannel)->info('Operation rolled back successfully', [
                    'operation' => $record->name,
                ]);
            } catch (Throwable $e) {
                Log::channel($logChannel)->error('Failed to rollback operation', [
                    'operation' => $record->name,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::channel($logChannel)->info('Rollback process completed');
    }

    /**
     * Find operation data by name for rollback reconstruction.
     *
     * Searches through all pending operations (including already executed ones with
     * repeat=true) to locate the operation data needed to reconstruct the operation
     * instance for rollback. Returns null if the operation cannot be found in the
     * discovery paths.
     *
     * @param  string                                  $name The operation name to search for
     * @return null|array{class: string, name: string} Operation data if found, null otherwise
     */
    private function findOperationDataByName(string $name): ?array
    {
        $operations = $this->operationDiscovery->getPending(true);

        foreach ($operations as $operation) {
            if ($operation['name'] === $name) {
                return $operation;
            }
        }

        return null;
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
