<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Orchestrators;

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\ConditionalExecution;
use Cline\Sequencer\Contracts\EnvironmentSpecific;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Contracts\Rollbackable;
use Cline\Sequencer\Contracts\Scheduled;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Events\OperationStarted;
use Cline\Sequencer\Exceptions\CannotAcquireLockException;
use Cline\Sequencer\Exceptions\InvalidOperationDataException;
use Cline\Sequencer\Exceptions\OperationMustImplementScheduledException;
use Cline\Sequencer\Exceptions\SkipOperationException;
use Cline\Sequencer\Exceptions\UnknownTaskTypeException;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Cline\Sequencer\Support\DependencyResolver;
use Cline\Sequencer\Support\MigrationDiscovery;
use Cline\Sequencer\Support\OperationDiscovery;
use Cline\Sequencer\Testing\OperationFake;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

use function array_filter;
use function array_reverse;
use function base_path;
use function config;
use function str_replace;
use function usort;

/**
 * Orchestrates scheduled execution of migrations and operations with time-based delays.
 *
 * Handles time-based delayed execution of operations implementing the Scheduled interface.
 * Operations are dispatched to the queue with delays calculated from their executeAt()
 * timestamp. Non-scheduled operations execute immediately. Asynchronous operations are
 * queued without delay. All execution happens sequentially with automatic rollback on failure.
 *
 * Use this orchestrator when you need to schedule operations for future execution at
 * specific times. Ideal for maintenance windows, batch processing at off-peak hours,
 * or operations that must run at precise times.
 *
 * ```php
 * // Operations can schedule their execution time
 * final readonly class ArchiveOldRecords implements Operation, Scheduled
 * {
 *     public function executeAt(): DateTimeInterface
 *     {
 *         // Run at 2 AM tomorrow
 *         return Date::tomorrow()->setTime(2, 0);
 *     }
 * }
 * ```
 *
 * @see Asynchronous For immediate background execution without scheduling
 * @see ConditionalExecution For operations that may skip based on runtime conditions
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class ScheduledOrchestrator implements Orchestrator
{
    /**
     * Create a new scheduled orchestrator instance.
     *
     * @param OperationDiscovery $operationDiscovery Service for discovering pending operations
     *                                               from configured discovery paths
     * @param MigrationDiscovery $migrationDiscovery Service for discovering pending Laravel
     *                                               migrations from migration directories
     * @param DependencyResolver $dependencyResolver Service for resolving and validating
     *                                               operation dependencies to ensure correct
     *                                               execution order
     */
    public function __construct(
        private OperationDiscovery $operationDiscovery,
        private MigrationDiscovery $migrationDiscovery,
        private DependencyResolver $dependencyResolver,
    ) {}

    /**
     * Execute all pending migrations and operations with scheduling support.
     *
     * @param bool        $isolate   Whether to use atomic lock for multi-server safety
     * @param bool        $dryRun    Preview execution without actually running tasks
     * @param null|string $from      Resume from specific timestamp (YYYY_MM_DD_HHMMSS)
     * @param bool        $repeat    Re-execute already-completed operations
     * @param bool        $forceSync Force synchronous execution (ignored for scheduled orchestrator)
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
     * @param  null|string                                                $from   Resume from specific timestamp
     * @param  bool                                                       $repeat Include already-executed operations
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
     * @param null|string $from   Resume from specific timestamp
     * @param bool        $repeat Re-execute already-completed operations
     *
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
     * Discover and execute all pending tasks with scheduling support.
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
                new NoPendingOperations(ExecutionMethod::Scheduled),
            );

            return;
        }

        Event::dispatch(
            new OperationsStarted(ExecutionMethod::Scheduled),
        );

        $executedOperations = [];

        try {
            foreach ($tasks as $task) {
                match ($task['type']) {
                    'migration' => $this->executeMigration($task),
                    'operation' => $executedOperations[] = $this->executeOperation($task),
                };
            }

            Event::dispatch(
                new OperationsEnded(ExecutionMethod::Scheduled),
            );
        } catch (Throwable $throwable) {
            // Rollback executed operations in reverse order
            $this->rollbackOperations(array_reverse($executedOperations));

            Event::dispatch(
                new OperationsEnded(ExecutionMethod::Scheduled),
            );

            throw $throwable;
        }
    }

    /**
     * Rollback operations that were executed before failure.
     *
     * @param list<array{operation: Operation, record: OperationModel}> $operations
     */
    private function rollbackOperations(array $operations): void
    {
        foreach ($operations as $item) {
            $operation = $item['operation'];
            $record = $item['record'];

            if (!$operation instanceof Rollbackable) {
                continue;
            }

            try {
                $operation->rollback();
                $record->update([
                    'rolled_back_at' => Date::now(),
                    'state' => OperationState::RolledBack,
                ]);

                /** @var string $logChannel */
                $logChannel = config('sequencer.errors.log_channel', 'stack');
                Log::channel($logChannel)
                    ->info('Operation rolled back successfully', [
                        'operation' => $record->name,
                    ]);
            } catch (Throwable $e) {
                /** @var string $logChannel */
                $logChannel = config('sequencer.errors.log_channel', 'stack');
                Log::channel($logChannel)
                    ->error('Failed to rollback operation', [
                        'operation' => $record->name,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
            }
        }
    }

    /**
     * Discover all pending migrations and operations, sorted by timestamp and dependencies.
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

        // Sort by timestamp for initial chronological ordering
        usort($tasks, fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        // Re-sort respecting dependencies
        return $this->dependencyResolver->sortByDependencies($tasks);
    }

    /**
     * Execute a single migration.
     *
     * @param array{type: string, timestamp: string, data: mixed} $task
     *
     * @throws Throwable
     */
    private function executeMigration(array $task): void
    {
        /** @var array{path: string, name: string} $migration */
        $migration = $task['data'];

        // Convert absolute path to relative path from Laravel base
        $relativePath = str_replace(base_path().'/', '', $migration['path']);

        Artisan::call('migrate', [
            '--path' => $relativePath,
            '--force' => true,
        ]);
    }

    /**
     * Execute a single operation with scheduling support.
     *
     * @param array{type: string, timestamp: string, data: mixed} $task
     *
     * @throws Throwable
     *
     * @return array{operation: Operation, record: OperationModel}
     */
    private function executeOperation(array $task): array
    {
        /** @var array{class: string, name: string} $operationData */
        $operationData = $task['data'];
        $operationPath = $operationData['class']; // This is actually the file path now
        $operationName = $operationData['name'];

        /** @var Operation $operation */
        $operation = require $operationPath;

        // Record for testing fake if enabled
        OperationFake::record($operationName, $operation);

        // If faking, skip actual execution
        if (OperationFake::isFaking()) {
            $record = OperationModel::query()->make([
                'name' => $operationName,
                'type' => 'fake',
                'executed_at' => Date::now(),
                'completed_at' => Date::now(),
            ]);

            return ['operation' => $operation, 'record' => $record];
        }

        // Check if operation should run in current environment
        if ($operation instanceof EnvironmentSpecific && !App::environment($operation->environments())) {
            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)
                ->info('Operation skipped due to environment restriction', [
                    'operation' => $operationName,
                    'allowed_environments' => $operation->environments(),
                    'current_environment' => App::environment(),
                ]);

            // Create a record showing it was skipped
            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::Scheduled,
                'executed_at' => Date::now(),
                'completed_at' => Date::now(),
                'state' => OperationState::Completed,
            ]);

            return ['operation' => $operation, 'record' => $record];
        }

        // Check if operation should run
        if ($operation instanceof ConditionalExecution && !$operation->shouldRun()) {
            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)
                ->info('Operation skipped by shouldRun() condition', [
                    'operation' => $operationName,
                ]);

            // Create a record showing it was skipped
            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => ExecutionMethod::Scheduled,
                'executed_at' => Date::now(),
                'completed_at' => Date::now(), // Mark as completed since it ran (decision was to skip)
                'state' => OperationState::Completed,
            ]);

            return ['operation' => $operation, 'record' => $record];
        }

        $isScheduled = $operation instanceof Scheduled;
        $isAsync = $operation instanceof Asynchronous;

        // Record operation start
        $record = OperationModel::query()->create([
            'name' => $operationName,
            'type' => ExecutionMethod::Scheduled,
            'executed_at' => Date::now(),
            'state' => OperationState::Pending,
        ]);

        if ($isScheduled) {
            $this->dispatchScheduled($operation, $record);

            return ['operation' => $operation, 'record' => $record];
        }

        if ($isAsync) {
            $this->dispatchAsync($operation, $record);

            return ['operation' => $operation, 'record' => $record];
        }

        $this->executeSynchronously($operation, $record);

        return ['operation' => $operation, 'record' => $record];
    }

    /**
     * Dispatch scheduled operation with time-based delay.
     *
     * Calculates the delay until the operation's scheduled execution time and
     * dispatches the job with that delay. If the scheduled time is in the past,
     * the operation executes immediately (delay of 0). Logs the scheduled
     * execution time for tracking purposes.
     *
     * @param Operation      $operation The scheduled operation to execute
     * @param OperationModel $record    The operation database record for tracking status
     */
    private function dispatchScheduled(Operation $operation, OperationModel $record): void
    {
        if (!$operation instanceof Scheduled) {
            throw OperationMustImplementScheduledException::forDispatch();
        }

        $executeAt = $operation->executeAt();
        $now = Date::now();
        $delay = $executeAt > $now ? (int) $now->diffInSeconds($executeAt) : 0;

        /** @var null|string $connection */
        $connection = config('sequencer.queue.connection');

        /** @var string $queueName */
        $queueName = config('sequencer.queue.queue', 'default');

        /** @var int|string $recordId */
        $recordId = $record->id;

        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');
        Log::channel($logChannel)
            ->info('Operation scheduled for execution', [
                'operation' => $record->name,
                'execute_at' => $executeAt->format('Y-m-d H:i:s'),
                'delay_seconds' => $delay,
            ]);

        Queue::connection($connection)->pushOn(
            $queueName,
            new ExecuteOperation($operation, $recordId)->delay($delay),
        );
    }

    /**
     * Dispatch operation to queue for asynchronous execution.
     *
     * Pushes the operation to the configured queue connection and queue name
     * for background processing. The operation will be executed by a queue
     * worker outside the current request cycle.
     *
     * @param Operation      $operation The operation instance to execute asynchronously
     * @param OperationModel $record    The operation database record for tracking status
     */
    private function dispatchAsync(Operation $operation, OperationModel $record): void
    {
        /** @var null|string $connection */
        $connection = config('sequencer.queue.connection');

        /** @var string $queueName */
        $queueName = config('sequencer.queue.queue', 'default');

        /** @var int|string $recordId */
        $recordId = $record->id;

        Queue::connection($connection)->pushOn(
            $queueName,
            new ExecuteOperation($operation, $recordId),
        );
    }

    /**
     * Execute operation synchronously with optional transaction wrapping.
     *
     * Runs the operation immediately in the current process. Automatically wraps
     * execution in a database transaction if the operation implements WithinTransaction
     * or if auto-transaction is enabled in configuration. Updates the operation
     * record with completion or failure status, and records detailed error
     * information if execution fails.
     *
     * @param Operation      $operation The operation instance to execute synchronously
     * @param OperationModel $record    The operation database record for tracking status
     *
     * @throws Throwable Re-throws any exception that occurs during operation execution
     *                   after recording the error details to the database
     */
    private function executeSynchronously(Operation $operation, OperationModel $record): void
    {
        Event::dispatch(
            new OperationStarted($operation, ExecutionMethod::Scheduled),
        );

        $autoTransaction = config('sequencer.execution.auto_transaction', true);
        $useTransaction = $operation instanceof WithinTransaction || $autoTransaction;

        try {
            if ($useTransaction) {
                DB::transaction(fn () => $operation->handle());
            } else {
                $operation->handle();
            }

            $record->update([
                'completed_at' => Date::now(),
                'state' => OperationState::Completed,
            ]);

            Event::dispatch(
                new OperationEnded($operation, ExecutionMethod::Scheduled),
            );
        } catch (SkipOperationException $exception) {
            $record->update([
                'skipped_at' => Date::now(),
                'skip_reason' => $exception->getMessage(),
                'state' => OperationState::Skipped,
            ]);

            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)->info('Operation skipped during execution', [
                'operation' => $record->name,
                'reason' => $exception->getMessage(),
            ]);

            Event::dispatch(
                new OperationEnded($operation, ExecutionMethod::Scheduled),
            );
        } catch (Throwable $throwable) {
            $record->update([
                'failed_at' => Date::now(),
                'state' => OperationState::Failed,
            ]);

            $this->recordError($record, $throwable);

            throw $throwable;
        }
    }

    /**
     * Record operation error for audit trail and logging.
     *
     * Creates a detailed error record in the operation_errors table containing
     * exception details, stack trace, and contextual information. Also logs the
     * error to the configured log channel for immediate alerting and monitoring.
     *
     * @param OperationModel $record    The operation database record that failed
     * @param Throwable      $exception The exception that caused the operation to fail
     */
    private function recordError(OperationModel $record, Throwable $exception): void
    {
        if (!config('sequencer.errors.record', true)) {
            return;
        }

        OperationError::query()->create([
            'operation_id' => $record->id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'context' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'code' => $exception->getCode(),
            ],
            'created_at' => Date::now(),
        ]);

        /** @var string $logChannel */
        $logChannel = config('sequencer.errors.log_channel', 'stack');
        Log::channel($logChannel)->error('Operation failed', [
            'operation' => $record->name,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
