<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer;

use Cline\Sequencer\Contracts\ConditionalExecution;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Contracts\Rollbackable;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Events\OperationStarted;
use Cline\Sequencer\Exceptions\OperationNotFoundException;
use Cline\Sequencer\Exceptions\OperationNotRollbackableException;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Cline\Sequencer\Support\OperationDiscovery;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

use function basename;
use function class_exists;
use function config;
use function database_path;
use function dispatch;
use function file_exists;
use function hrtime;
use function is_string;
use function resolve;
use function sprintf;
use function str_ends_with;

/**
 * Facade for programmatic operation execution and lifecycle management.
 *
 * Provides a high-level API for executing operations synchronously or asynchronously,
 * with support for multiple orchestration strategies (sequential, batch, transactional),
 * conditional execution, chaining, batching, rollback capabilities, and comprehensive
 * error tracking. Serves as the primary entry point for operation execution outside
 * of Artisan commands.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SequencerManager
{
    /**
     * Custom orchestrator instance for overriding default execution strategy.
     *
     * Set via the using() method to temporarily override the configured default
     * orchestrator for a specific execution chain. Remains null when using the
     * default orchestrator configured in the constructor or config file.
     */
    private ?Orchestrator $customOrchestrator = null;

    /**
     * Create a new Sequencer manager instance.
     *
     * @param Orchestrator       $orchestrator Default orchestrator for operation execution
     * @param OperationDiscovery $discovery    Service for discovering pending operations in filesystem
     */
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly OperationDiscovery $discovery,
    ) {}

    /**
     * Use a custom orchestrator for this execution chain.
     *
     * Enables fluent API for selecting orchestration strategy without modifying
     * configuration. Clones the manager instance to avoid side effects on the
     * original singleton binding.
     *
     * ```php
     * Sequencer::using(BatchOrchestrator::class)->executeAll();
     * Sequencer::using(TransactionalBatchOrchestrator::class)->executeAll();
     * ```
     *
     * @param  class-string<Orchestrator>|Orchestrator $orchestrator Orchestrator class name or instance
     * @return self                                    Cloned manager instance with custom orchestrator
     */
    public function using(string|Orchestrator $orchestrator): self
    {
        $instance = clone $this;
        $instance->customOrchestrator = is_string($orchestrator)
            ? resolve($orchestrator)
            : $orchestrator;

        return $instance;
    }

    /**
     * Execute all pending operations using the configured orchestrator.
     *
     * Delegates to the active orchestrator (custom or default) to discover and
     * execute all pending operations. The execution strategy depends on the
     * orchestrator implementation (sequential, batch, transactional, etc.).
     *
     * @param bool        $isolate Use atomic lock to prevent concurrent execution across servers
     * @param null|string $from    Resume from specific timestamp (format: Y-m-d_His)
     * @param bool        $repeat  Re-execute previously completed operations
     *
     * @throws Throwable When operation execution fails without error suppression
     */
    public function executeAll(bool $isolate = false, ?string $from = null, bool $repeat = false): void
    {
        $this->getOrchestrator()->process(isolate: $isolate, from: $from, repeat: $repeat);
    }

    /**
     * Preview pending operations without executing them.
     *
     * Performs a dry-run to discover which operations would be executed with the
     * current configuration. Useful for verifying operation order and selection
     * before actual execution.
     *
     * @param  null|string                                                $from   Resume from specific timestamp (format: Y-m-d_His)
     * @param  bool                                                       $repeat Include previously executed operations
     * @return list<array{type: string, timestamp: string, name: string}> List of operations that would be executed
     */
    public function preview(?string $from = null, bool $repeat = false): array
    {
        return $this->getOrchestrator()->process(dryRun: true, from: $from, repeat: $repeat) ?? [];
    }

    /**
     * Execute a specific operation by class name or file name.
     *
     * Loads and executes a single operation either synchronously or asynchronously.
     * Supports both fully-qualified class names and file-based operation references.
     * When recording is enabled, creates database records for tracking execution
     * status, completion, and error handling.
     *
     * @param class-string<Operation>|string $operation Fully-qualified class name or file name (with or without .php)
     * @param bool                           $async     Dispatch to queue for background execution
     * @param bool                           $record    Create database record for execution tracking
     * @param null|string                    $queue     Optional queue name for async execution
     *
     * @throws OperationNotFoundException When operation file or class cannot be found
     * @throws Throwable                  When operation execution fails
     */
    public function execute(string $operation, bool $async = false, bool $record = true, ?string $queue = null): void
    {
        $instance = $this->loadOperation($operation);
        $operationName = $this->resolveOperationName($operation);

        if ($record) {
            $this->executeWithRecord($instance, $operationName, $async, $queue);
        } else {
            $this->executeDirect($instance);
        }
    }

    /**
     * Conditionally execute an operation when condition is true.
     *
     * Provides guard clause pattern for conditional execution. Operation is only
     * executed when the condition evaluates to true, avoiding unnecessary operation
     * loading and initialization when condition is false.
     *
     * @param bool                           $condition Condition that must be true for execution
     * @param class-string<Operation>|string $operation Fully-qualified class name or file name
     * @param bool                           $async     Dispatch to queue for background execution
     * @param bool                           $record    Create database record for execution tracking
     *
     * @throws OperationNotFoundException When operation file or class cannot be found
     * @throws Throwable                  When operation execution fails
     */
    public function executeIf(bool $condition, string $operation, bool $async = false, bool $record = true): void
    {
        if ($condition) {
            $this->execute($operation, $async, $record);
        }
    }

    /**
     * Conditionally execute an operation when condition is false.
     *
     * Provides inverse guard clause pattern for conditional execution. Operation
     * is only executed when the condition evaluates to false, offering cleaner
     * syntax for negative conditional logic.
     *
     * @param bool                           $condition Condition that must be false for execution
     * @param class-string<Operation>|string $operation Fully-qualified class name or file name
     * @param bool                           $async     Dispatch to queue for background execution
     * @param bool                           $record    Create database record for execution tracking
     *
     * @throws OperationNotFoundException When operation file or class cannot be found
     * @throws Throwable                  When operation execution fails
     */
    public function executeUnless(bool $condition, string $operation, bool $async = false, bool $record = true): void
    {
        if (!$condition) {
            $this->execute($operation, $async, $record);
        }
    }

    /**
     * Execute an operation synchronously in the current process.
     *
     * Forces synchronous execution regardless of queue configuration. Useful when
     * immediate execution is required or when running in environments where queue
     * workers are unavailable.
     *
     * @param class-string<Operation>|string $operation Fully-qualified class name or file name
     * @param bool                           $record    Create database record for execution tracking
     *
     * @throws OperationNotFoundException When operation file or class cannot be found
     * @throws Throwable                  When operation execution fails
     */
    public function executeSync(string $operation, bool $record = true): void
    {
        $this->execute($operation, async: false, record: $record);
    }

    /**
     * Chain multiple operations for sequential execution on the queue.
     *
     * Creates a Laravel job chain where operations execute one after another,
     * with each operation waiting for the previous to complete. If any operation
     * fails, subsequent operations in the chain are not executed unless catch
     * callbacks are configured on the returned PendingChain.
     *
     * @param array<class-string<Operation>|string> $operations Operations to execute sequentially
     *
     * @throws OperationNotFoundException When any operation file or class cannot be found
     * @throws RuntimeException           When chain cannot be created
     *
     * @return PendingChain Pending chain for callback configuration and dispatch
     */
    public function chain(array $operations): PendingChain
    {
        $jobs = [];

        foreach ($operations as $operation) {
            $instance = $this->loadOperation($operation);
            $operationName = $this->resolveOperationName($operation);

            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => 'async',
                'state' => OperationState::Pending,
                'executed_at' => Date::now(),
            ]);

            /** @var int|string $recordId */
            $recordId = $record->id;
            $jobs[] = new ExecuteOperation($instance, $recordId);
        }

        return Bus::chain($jobs);
    }

    /**
     * Batch multiple operations for parallel execution on the queue.
     *
     * Creates a Laravel job batch where operations can execute concurrently across
     * multiple queue workers. All operations are tracked together, allowing for
     * coordinated completion and error handling through batch callbacks. Operations
     * can dynamically add more jobs to the batch during execution.
     *
     * @param array<class-string<Operation>|string> $operations Operations to execute in parallel
     *
     * @throws OperationNotFoundException When any operation file or class cannot be found
     * @throws RuntimeException           When batch cannot be created
     *
     * @return PendingBatch Pending batch for callback configuration and dispatch
     */
    public function batch(array $operations): PendingBatch
    {
        $jobs = [];

        foreach ($operations as $operation) {
            $instance = $this->loadOperation($operation);
            $operationName = $this->resolveOperationName($operation);

            $record = OperationModel::query()->create([
                'name' => $operationName,
                'type' => 'async',
                'state' => OperationState::Pending,
                'executed_at' => Date::now(),
            ]);

            /** @var int|string $recordId */
            $recordId = $record->id;
            $jobs[] = new ExecuteOperation($instance, $recordId);
        }

        return Bus::batch($jobs);
    }

    /**
     * Rollback a previously executed operation.
     *
     * Executes the rollback logic for operations implementing the Rollbackable
     * interface. When recording is enabled, updates the database record with
     * rollback timestamp and state change. Useful for reverting changes during
     * debugging or when operation effects need to be undone.
     *
     * @param class-string<Operation>|string $operation Fully-qualified class name or file name
     * @param bool                           $record    Update database with rolled_back_at timestamp
     *
     * @throws OperationNotFoundException        When operation file or class cannot be found
     * @throws OperationNotRollbackableException When operation does not implement Rollbackable
     * @throws Throwable                         When rollback execution fails
     */
    public function rollback(string $operation, bool $record = true): void
    {
        $instance = $this->loadOperation($operation);

        if (!$instance instanceof Rollbackable) {
            throw OperationNotRollbackableException::doesNotImplementInterface();
        }

        $instance->rollback();

        if ($record) {
            $operationName = $this->resolveOperationName($operation);
            $operationRecord = OperationModel::query()->where('name', $operationName)->first();

            if ($operationRecord) {
                $operationRecord->update([
                    'rolled_back_at' => Date::now(),
                    'state' => OperationState::RolledBack,
                ]);
            }
        }
    }

    /**
     * Check if an operation has been successfully executed.
     *
     * Queries the database to determine if the operation has completed execution.
     * Only returns true if the operation has a completed_at timestamp, indicating
     * successful completion. Failed or pending operations return false.
     *
     * @param  class-string<Operation>|string $operation Fully-qualified class name or file name
     * @return bool                           True if operation completed successfully
     */
    public function hasExecuted(string $operation): bool
    {
        $operationName = $this->resolveOperationName($operation);

        return OperationModel::query()->where('name', $operationName)
            ->whereNotNull('completed_at')
            ->exists();
    }

    /**
     * Check if an operation has failed during execution.
     *
     * Queries the database to determine if the operation encountered an error
     * during execution. Returns true if the operation has a failed_at timestamp,
     * indicating an exception was thrown during execution.
     *
     * @param  class-string<Operation>|string $operation Fully-qualified class name or file name
     * @return bool                           True if operation failed with an exception
     */
    public function hasFailed(string $operation): bool
    {
        $operationName = $this->resolveOperationName($operation);

        return OperationModel::query()->where('name', $operationName)
            ->whereNotNull('failed_at')
            ->exists();
    }

    /**
     * Retrieve all error records for a specific operation.
     *
     * Fetches the complete error history for an operation, including exception
     * class names, messages, stack traces, and contextual information. Returns
     * an empty collection if the operation has no errors or has not been executed.
     *
     * @param  class-string<Operation>|string  $operation Fully-qualified class name or file name
     * @return Collection<int, OperationError> Collection of error records
     */
    public function getErrors(string $operation): Collection
    {
        $operationName = $this->resolveOperationName($operation);
        $operationRecord = OperationModel::query()->where('name', $operationName)->first();

        if (!$operationRecord) {
            return new Collection();
        }

        return $operationRecord->errors;
    }

    /**
     * Resolve the orchestrator to use for execution.
     *
     * Returns the custom orchestrator if set via using(), otherwise checks the
     * configuration file for a custom orchestrator, falling back to the constructor
     * injected default orchestrator. This three-tier resolution allows for maximum
     * flexibility in orchestrator selection.
     *
     * @return Orchestrator The resolved orchestrator instance
     */
    private function getOrchestrator(): Orchestrator
    {
        if ($this->customOrchestrator instanceof Orchestrator) {
            return $this->customOrchestrator;
        }

        /** @var null|class-string<Orchestrator> $configOrchestrator */
        $configOrchestrator = config('sequencer.orchestrator');

        if ($configOrchestrator !== null) {
            return resolve($configOrchestrator);
        }

        return $this->orchestrator;
    }

    /**
     * Load and instantiate an operation from class name or file path.
     *
     * Attempts to load the operation first as a fully-qualified class name,
     * then as a file-based operation if class loading fails. Supports both
     * absolute paths and relative file names within configured discovery paths.
     *
     * @param class-string<Operation>|string $operation Fully-qualified class name or file name
     *
     * @throws OperationNotFoundException When operation cannot be found as class or file
     *
     * @return Operation Instantiated operation ready for execution
     */
    private function loadOperation(string $operation): Operation
    {
        // Try as class name first
        if (class_exists($operation)) {
            /** @var Operation $instance */
            $instance = new $operation();

            return $instance;
        }

        // Try as file name
        $path = $this->resolveOperationPath($operation);

        if (!file_exists($path)) {
            throw OperationNotFoundException::forOperation($operation);
        }

        /** @var Operation $instance */
        $instance = require $path;

        return $instance;
    }

    /**
     * Resolve the canonical operation name for database storage.
     *
     * Converts both class names and file paths to a consistent operation name
     * format for database record lookup. When given a class name, attempts to
     * find the corresponding file name via discovery. When given a file path,
     * extracts the base name without extension.
     *
     * @param  class-string<Operation>|string $operation Fully-qualified class name or file name
     * @return string                         Canonical operation name for database queries
     */
    private function resolveOperationName(string $operation): string
    {
        // If it's a class name, find the corresponding file
        if (class_exists($operation)) {
            $pending = $this->discovery->getPending(repeat: true);

            foreach ($pending as $pendingOp) {
                /** @var Operation $instance */
                $instance = require $pendingOp['class'];

                if ($instance::class === $operation) {
                    return $pendingOp['name'];
                }
            }

            // Fallback to class name
            return $operation;
        }

        // If it's a file name, extract the name part
        if (str_ends_with($operation, '.php')) {
            return basename($operation, '.php');
        }

        return $operation;
    }

    /**
     * Resolve the full filesystem path to an operation file.
     *
     * Attempts to locate the operation file by checking multiple locations in order:
     * 1. As an absolute path if already fully qualified
     * 2. In the default database/operations directory
     * 3. In all configured discovery paths from configuration
     *
     * @param  string $operation File name or path to the operation file
     * @return string Full filesystem path to the operation file
     */
    private function resolveOperationPath(string $operation): string
    {
        // If it's already a full path
        if (file_exists($operation)) {
            return $operation;
        }

        // Try with .php extension
        if (!str_ends_with($operation, '.php')) {
            $operation .= '.php';
        }

        // Check in database/operations
        $path = database_path('operations/'.$operation);

        if (file_exists($path)) {
            return $path;
        }

        // Check in all discovery paths
        /** @var array<string> $discoveryPaths */
        $discoveryPaths = config('sequencer.execution.discovery_paths', [database_path('operations')]);

        foreach ($discoveryPaths as $discoveryPath) {
            $fullPath = sprintf('%s/%s', $discoveryPath, $operation);

            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return $path; // Return default path for error message
    }

    /**
     * Execute an operation with database record tracking.
     *
     * Creates a database record before execution to track operation lifecycle,
     * state transitions, and errors. Supports both synchronous and asynchronous
     * execution modes. Checks conditional execution interface before proceeding.
     *
     * @param Operation $operation     Instantiated operation to execute
     * @param string    $operationName Canonical operation name for database
     * @param bool      $async         Dispatch to queue instead of executing immediately
     *
     * @throws Throwable When synchronous execution fails
     */
    private function executeWithRecord(Operation $operation, string $operationName, bool $async, ?string $queue = null): void
    {
        // Check conditional execution
        if ($operation instanceof ConditionalExecution && !$operation->shouldRun()) {
            /** @var string $logChannel */
            $logChannel = config('sequencer.errors.log_channel', 'stack');
            Log::channel($logChannel)->info('Operation skipped by shouldRun() condition', [
                'operation' => $operationName,
            ]);

            return;
        }

        $record = OperationModel::query()->create([
            'name' => $operationName,
            'type' => $async ? ExecutionMethod::Async->value : ExecutionMethod::Sync->value,
            'state' => OperationState::Pending,
            'executed_at' => Date::now(),
        ]);

        if ($async) {
            /** @var null|string $connection */
            $connection = config('sequencer.queue.connection');

            /** @var string $queueName */
            $queueName = $queue ?? config('sequencer.queue.queue', 'default');

            /** @var int|string $recordId */
            $recordId = $record->id;

            dispatch(
                new ExecuteOperation($operation, $recordId),
            )
                ->onConnection($connection)
                ->onQueue($queueName);

            return;
        }

        $this->executeSynchronously($operation, $record);
    }

    /**
     * Execute an operation directly without database record tracking.
     *
     * Executes the operation immediately in the current process without creating
     * database records for tracking. Fires lifecycle events and respects transaction
     * configuration based on operation interfaces and configuration settings.
     *
     * @param Operation $operation Instantiated operation to execute
     *
     * @throws Throwable When operation execution fails
     */
    private function executeDirect(Operation $operation): void
    {
        $startTime = hrtime(true);

        Event::dispatch(
            new OperationStarted($operation, ExecutionMethod::Sync),
        );

        $autoTransaction = config('sequencer.execution.auto_transaction', true);
        $useTransaction = $operation instanceof WithinTransaction || $autoTransaction;

        if ($useTransaction) {
            DB::transaction(fn () => $operation->handle());
        } else {
            $operation->handle();
        }

        $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

        Event::dispatch(
            new OperationEnded($operation, ExecutionMethod::Sync, $elapsedMs),
        );
    }

    /**
     * Execute an operation synchronously with comprehensive error handling.
     *
     * Wraps operation execution with try-catch to capture errors, update database
     * records with completion or failure timestamps, fire lifecycle events, and
     * create detailed error records for audit trails. Respects transaction settings
     * from operation interfaces and configuration.
     *
     * @param Operation      $operation Operation instance to execute
     * @param OperationModel $record    Database record for tracking execution state
     *
     * @throws Throwable Re-throws the original exception after recording error details
     */
    private function executeSynchronously(Operation $operation, OperationModel $record): void
    {
        $startTime = hrtime(true);

        Event::dispatch(
            new OperationStarted($operation, ExecutionMethod::Sync),
        );

        $autoTransaction = config('sequencer.execution.auto_transaction', true);
        $useTransaction = $operation instanceof WithinTransaction || $autoTransaction;

        try {
            if ($useTransaction) {
                DB::transaction(fn () => $operation->handle());
            } else {
                $operation->handle();
            }

            $record->update(['completed_at' => Date::now()]);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

            Event::dispatch(
                new OperationEnded($operation, ExecutionMethod::Sync, $elapsedMs),
            );
        } catch (Throwable $throwable) {
            $record->update(['failed_at' => Date::now()]);

            $this->recordError($record, $throwable);

            throw $throwable;
        }
    }

    /**
     * Record detailed error information for failed operation execution.
     *
     * Creates a comprehensive error record containing exception details, stack trace,
     * and contextual information for debugging and audit purposes. Also logs the error
     * to the configured log channel. Respects configuration setting for error recording.
     *
     * @param OperationModel $record    Database record for the failed operation
     * @param Throwable      $exception Exception thrown during operation execution
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
