<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Orchestrators;

use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Enums\ExecutionMethod;
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
use Throwable;

use function array_filter;
use function array_values;
use function base_path;
use function config;
use function str_replace;
use function usort;

/**
 * Orchestrates parallel batch execution of operations with strict failure handling.
 *
 * Executes all operations in parallel using Laravel's batch system for maximum
 * throughput. Unlike AllowedToFailBatchOrchestrator, this orchestrator does not
 * use allowFailures() - any operation failure will cause the entire batch to fail.
 * Migrations are executed sequentially first to maintain schema integrity.
 *
 * Use this orchestrator when you need parallel execution but require strict
 * all-or-nothing semantics where any failure should halt the entire batch.
 * Best for tightly coupled operations where partial completion is unacceptable.
 *
 * ```php
 * // All operations must succeed for batch to complete
 * final readonly class ProcessCriticalData implements Operation
 * {
 *     public function handle(): void
 *     {
 *         // Any failure here will fail the entire batch
 *     }
 * }
 * ```
 *
 * @see AllowedToFailBatchOrchestrator For fault-tolerant batch execution
 * @see TransactionalBatchOrchestrator For batch execution with automatic rollback
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class BatchOrchestrator implements Orchestrator
{
    /**
     * Create a new batch orchestrator instance.
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
     * Execute all pending migrations and operations with strict batch processing.
     *
     * Migrations are executed sequentially first to maintain schema integrity, then all
     * operations are dispatched as a single Laravel batch for parallel execution. Any
     * operation failure will cause the entire batch to fail without rollback.
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

        // @phpstan-ignore-next-line Laravel's Cache facade provides lock() method via macro
        $lock = Cache::store($lockStore)->lock('sequencer:process', $ttl);

        // @phpstan-ignore-next-line Lock instance provides block() method
        if (!$lock->block($timeout)) {
            throw CannotAcquireLockException::timeoutExceeded();
        }

        try {
            $this->execute($from, $repeat);
        } finally {
            // @phpstan-ignore-next-line Lock instance provides release() method
            $lock->release();
        }
    }

    /**
     * Discover and execute all pending tasks with strict batch processing.
     *
     * Executes migrations sequentially first to ensure database schema is current,
     * then dispatches all operations as a single batch for parallel execution. Any
     * operation failure will cause the entire batch to fail.
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
                new NoPendingOperations(ExecutionMethod::Batch),
            );

            return;
        }

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::Batch),
        );

        // Separate migrations and operations
        $migrations = array_filter($tasks, fn (array $task): bool => $task['type'] === 'migration');
        $operations = array_values(array_filter($tasks, fn (array $task): bool => $task['type'] === 'operation'));

        // Execute migrations sequentially (they must maintain order)
        foreach ($migrations as $migration) {
            $this->executeMigration($migration);
        }

        // Batch all operations for parallel execution
        if ($operations !== []) {
            $this->executeBatch($operations);
        }

        Event::dispatch(
            new OperationsEnded(ExecutionMethod::Batch),
        );
    }

    /**
     * Execute operations as a strict Laravel batch without fault tolerance.
     *
     * Creates database records for all operations, wraps them in ExecuteOperation jobs,
     * and dispatches as a batch without allowFailures(). Any job failure will cause the
     * entire batch to fail. This provides strict all-or-nothing semantics for critical
     * operations that must all succeed together.
     *
     * @param list<array{type: string, timestamp: string, data: mixed}> $operations
     *
     * @throws Throwable
     *
     * @return Batch The dispatched batch instance for monitoring
     */
    private function executeBatch(array $operations): Batch
    {
        $jobs = [];

        foreach ($operations as $task) {
            /** @var array{class: string, name: string} $operationData */
            $operationData = $task['data'];
            $operationPath = $operationData['class'];
            $operationName = $operationData['name'];

            /** @var \Cline\Sequencer\Contracts\Operation $operation */
            $operation = require $operationPath;

            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::Batch,
                'executed_at' => Date::now(),
            ]);

            /** @var int|string $recordId */
            $recordId = $record->id;
            $jobs[] = new ExecuteOperation($operation, $recordId);
        }

        return Bus::batch($jobs)
            ->name('Sequencer Operations Batch')
            ->dispatch();
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
