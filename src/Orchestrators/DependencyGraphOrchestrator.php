<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Orchestrators;

use Cline\Sequencer\Contracts\HasDependencies;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Exceptions\CannotAcquireLockException;
use Cline\Sequencer\Exceptions\CircularDependencyException;
use Cline\Sequencer\Exceptions\InvalidOperationDataException;
use Cline\Sequencer\Exceptions\UnknownTaskTypeException;
use Cline\Sequencer\Exceptions\WaveExecutionFailedException;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Cline\Sequencer\Support\DependencyResolver;
use Cline\Sequencer\Support\GuardManager;
use Cline\Sequencer\Support\MigrationDiscovery;
use Cline\Sequencer\Support\OperationDiscovery;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_values;
use function base_path;
use function config;
use function in_array;
use function str_replace;
use function usort;

/**
 * Orchestrates dependency-aware wave-based execution of operations.
 *
 * Executes operations in topologically-sorted waves where each wave contains
 * operations that can run in parallel. Operations in a wave have no dependencies
 * on each other but may depend on operations from previous waves. Migrations
 * always execute sequentially first before any operation waves begin.
 *
 * Wave execution strategy:
 * - Wave 1: Operations with no dependencies
 * - Wave 2: Operations depending only on Wave 1
 * - Wave N: Operations depending only on Waves 1 through N-1
 *
 * Each wave dispatches as a batch job and waits for completion before proceeding
 * to the next wave, ensuring dependency constraints are satisfied while maximizing
 * parallel execution opportunities. Detects circular dependencies and fails fast.
 *
 * Use this orchestrator when you have complex inter-operation dependencies and
 * need to maximize parallelism while respecting dependency constraints. Ideal
 * for data processing pipelines where some operations depend on others completing.
 *
 * ```php
 * // Operations declare their dependencies
 * final readonly class ProcessUserOrders implements Operation, HasDependencies
 * {
 *     public function dependsOn(): array
 *     {
 *         return [ImportUsers::class]; // Runs after ImportUsers completes
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class DependencyGraphOrchestrator implements Orchestrator
{
    /**
     * Create a new dependency graph orchestrator instance.
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
        private GuardManager $guardManager,
    ) {}

    /**
     * Execute all pending migrations and operations in dependency-aware waves.
     *
     * Migrations are executed sequentially first to maintain schema integrity, then
     * operations are grouped into waves based on their dependencies. Each wave executes
     * in parallel as a batch, with waves running sequentially to satisfy dependencies.
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
     * Discover and execute all pending tasks in dependency-aware waves.
     *
     * Executes migrations sequentially first to ensure database schema is current,
     * then groups operations into waves based on dependencies and executes each
     * wave as a parallel batch, waiting for wave completion before proceeding.
     *
     * @param null|string $from   Resume from specific timestamp
     * @param bool        $repeat Re-execute already-completed operations
     *
     * @throws Throwable
     */
    private function execute(?string $from = null, bool $repeat = false): void
    {
        // Check all configured guards before executing any operations
        $this->guardManager->check();

        $tasks = $this->discoverPendingTasks($repeat);

        if ($from) {
            $tasks = array_filter($tasks, fn (array $task): bool => $task['timestamp'] >= $from);
        }

        if ($tasks === []) {
            Event::dispatch(
                new NoPendingOperations(ExecutionMethod::DependencyGraph),
            );

            return;
        }

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::DependencyGraph),
        );

        // Separate migrations and operations
        $migrations = array_filter($tasks, fn (array $task): bool => $task['type'] === 'migration');
        $operations = array_values(array_filter($tasks, fn (array $task): bool => $task['type'] === 'operation'));

        // Execute migrations sequentially (they must maintain order)
        foreach ($migrations as $migration) {
            $this->executeMigration($migration);
        }

        // Execute operations in dependency-aware waves
        if ($operations !== []) {
            $this->executeWaves($operations);
        }

        Event::dispatch(
            new OperationsEnded(ExecutionMethod::DependencyGraph),
        );
    }

    /**
     * Execute operations in dependency-aware waves with sequential wave progression.
     *
     * Groups operations into waves using topological sorting where each wave contains
     * operations that can execute in parallel without inter-dependencies. Processes
     * waves sequentially to ensure all dependencies are satisfied before dependent
     * operations execute. Each wave completes fully before the next begins.
     *
     * @param list<array{type: string, timestamp: string, data: mixed, operation?: Operation}> $operations
     *
     * @throws CircularDependencyException  When circular dependencies are detected
     * @throws Throwable
     * @throws WaveExecutionFailedException When any operation in a wave fails
     */
    private function executeWaves(array $operations): void
    {
        $waves = $this->buildExecutionWaves($operations);

        foreach ($waves as $waveNumber => $wave) {
            $this->executeWave($wave, $waveNumber + 1);
        }
    }

    /**
     * Execute a single wave of operations as a blocking batch.
     *
     * Dispatches all operations in the wave as a Laravel batch job and uses
     * batch callbacks to handle completion. This blocking behavior ensures all
     * operations in the wave finish successfully before proceeding to the next
     * wave. If any job fails, throws an exception with details about the failed
     * wave and job count.
     *
     * @param list<array{type: string, timestamp: string, data: mixed, operation?: Operation}> $operations Operations to execute in this wave
     * @param int                                                                              $waveNumber Wave number for logging and error reporting
     *
     * @throws Throwable
     * @throws WaveExecutionFailedException When any operation in the wave fails
     */
    private function executeWave(array $operations, int $waveNumber): void
    {
        $jobs = [];

        foreach ($operations as $task) {
            /** @var array{class: string, name: string} $operationData */
            $operationData = $task['data'];
            $operationPath = $operationData['class'];
            $operationName = $operationData['name'];

            /** @var Operation $operation */
            $operation = $task['operation'] ?? require $operationPath;

            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::DependencyGraph,
                'state' => OperationState::Pending,
                'executed_at' => Date::now(),
            ]);

            /** @var int|string $recordId */
            $recordId = $record->id;

            $jobs[] = new ExecuteOperation($operation, $recordId);
        }

        $completed = false;
        $failed = false;
        $failureCount = 0;

        // Dispatch batch with callbacks
        Bus::batch($jobs)
            ->name('Sequencer Dependency Graph Wave '.$waveNumber)
            ->then(function () use (&$completed): void {
                $completed = true;
            })
            ->catch(function (Batch $batch) use (&$failed, &$failureCount): void {
                $failed = true;
                $failureCount = $batch->failedJobs;
            })
            ->dispatch();

        // Wait for batch to complete
        while (!$completed && !$failed) {
            Sleep::for(1)->seconds();
        }

        // Check if batch failed
        if ($failed) {
            throw WaveExecutionFailedException::forWave($waveNumber, $failureCount);
        }
    }

    /**
     * Build execution waves from operations using topological sort.
     *
     * Groups operations into waves where each wave contains operations that:
     * 1. Have no dependencies on each other (can run in parallel)
     * 2. Only depend on operations from previous waves
     *
     * Algorithm:
     * - Load all operations and identify their dependencies
     * - Build dependency graph from HasDependencies declarations
     * - Group into waves using topological sorting
     * - Wave 1: Operations with no dependencies
     * - Wave N+1: Operations whose dependencies are all in waves 1-N
     *
     * @param  list<array{type: string, timestamp: string, data: mixed, operation?: Operation}>      $operations
     * @return list<list<array{type: string, timestamp: string, data: mixed, operation: Operation}>>
     */
    private function buildExecutionWaves(array $operations): array
    {
        // Load all operations first
        $loadedOperations = [];

        foreach ($operations as $task) {
            if (!array_key_exists('operation', $task)) {
                /** @var array{class: string} $data */
                $data = $task['data'];

                /** @var Operation $operation */
                $operation = require $data['class'];
                $task['operation'] = $operation;
            }

            /** @var array{type: string, timestamp: string, data: mixed, operation: Operation} $task */
            $loadedOperations[] = $task;
        }

        $waves = [];
        $remaining = $loadedOperations;
        $completedInPreviousWaves = [];

        // Build waves iteratively
        while ($remaining !== []) {
            $currentWave = [];

            foreach ($remaining as $key => $task) {
                /** @var Operation $operation */
                $operation = $task['operation'];

                if (!$this->canExecuteInCurrentWave($operation, $completedInPreviousWaves)) {
                    continue;
                }

                $currentWave[] = $task;

                /** @var array{class: string} $data */
                $data = $task['data'];
                $completedInPreviousWaves[] = $data['class'];
                unset($remaining[$key]);
            }

            // If no operations could be added to this wave, we have a circular dependency
            if ($currentWave === []) {
                throw CircularDependencyException::detected();
            }

            $waves[] = $currentWave;
            $remaining = array_values($remaining);
        }

        return $waves;
    }

    /**
     * Check if an operation can execute in the current wave.
     *
     * An operation can execute in the current wave if all its declared dependencies
     * have already been completed in previous waves. Operations without dependencies
     * (not implementing HasDependencies) can always execute in the current wave.
     * Migration dependencies are considered already satisfied.
     *
     * @param  Operation    $operation                The operation to check
     * @param  list<string> $completedInPreviousWaves Array of operation class names completed in prior waves
     * @return bool         True if all dependencies are satisfied, false otherwise
     */
    private function canExecuteInCurrentWave(Operation $operation, array $completedInPreviousWaves): bool
    {
        if (!$operation instanceof HasDependencies) {
            return true;
        }

        $dependencies = $operation->dependsOn();

        // All dependencies must be in previous waves or already satisfied
        foreach ($dependencies as $dependency) {
            // Check if it's a migration dependency (already executed)
            if ($this->dependencyResolver->dependenciesSatisfied($operation)) {
                continue;
            }

            // Check if dependency is in a previous wave
            if (!in_array($dependency, $completedInPreviousWaves, true)) {
                return false;
            }
        }

        return true;
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
