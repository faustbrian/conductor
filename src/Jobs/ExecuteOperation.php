<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Jobs;

use Cline\Sequencer\Contracts\HasLifecycleHooks;
use Cline\Sequencer\Contracts\HasMaxExceptions;
use Cline\Sequencer\Contracts\HasMiddleware;
use Cline\Sequencer\Contracts\HasTags;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Retryable;
use Cline\Sequencer\Contracts\ShouldBeUnique;
use Cline\Sequencer\Contracts\Timeoutable;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Events\OperationEnded;
use Cline\Sequencer\Events\OperationStarted;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted as LaravelShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique as LaravelShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

use function array_values;
use function config;
use function hrtime;
use function is_array;

/**
 * Queue job for executing operations asynchronously.
 *
 * Dispatched by SequentialOrchestrator for operations implementing the Asynchronous
 * interface. Provides identical transaction wrapping, error handling, and audit trail
 * functionality as synchronous operations, enabling background processing without
 * sacrificing reliability or traceability.
 *
 * The job automatically configures itself based on operation-specific interfaces:
 * - Retryable: sets tries, backoff, and retryUntil from operation
 * - Timeoutable: sets timeout and failOnTimeout from operation
 * - HasMaxExceptions: sets maxExceptions from operation
 * - HasMiddleware: applies operation-defined middleware stack
 * - HasTags: applies operation-defined tags for monitoring
 * - ShouldBeUnique: enforces uniqueness constraints from operation
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ExecuteOperation implements LaravelShouldBeEncrypted, LaravelShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * Automatically configured from operation's tries() method if it implements
     * the Retryable interface. Defaults to null which uses queue configuration.
     */
    public ?int $tries = null;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * Supports both fixed delays (int) and exponential backoff (array of delays).
     * Automatically configured from operation's backoff() method if it implements
     * the Retryable interface. Defaults to null which uses queue configuration.
     *
     * @var null|array<int, int>|int
     */
    public array|int|null $backoff = null;

    /**
     * The DateTime when the job should stop attempting retries.
     *
     * Automatically configured from operation's retryUntil() method if it implements
     * the Retryable interface. After this time, the job will not be retried even if
     * it hasn't reached the maximum tries limit.
     */
    public ?DateTimeInterface $retryUntil = null;

    /**
     * The number of seconds the job can run before timing out.
     *
     * Automatically configured from operation's timeout() method if it implements
     * the Timeoutable interface. Defaults to null which uses queue configuration.
     */
    public ?int $timeout = null;

    /**
     * Indicates if the job should be marked as failed on timeout.
     *
     * Automatically configured from operation's failOnTimeout() method if it implements
     * the Timeoutable interface. When false, the job is released back to the queue on
     * timeout instead of being marked as failed.
     */
    public bool $failOnTimeout = false;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * Automatically configured from operation's maxExceptions() method if it implements
     * the HasMaxExceptions interface. Useful for operations with intermittent failures
     * that should eventually succeed without exhausting retry limits.
     */
    public ?int $maxExceptions = null;

    /**
     * Create a new job instance.
     *
     * Automatically configures job behavior based on operation interfaces. If the operation
     * implements Retryable, Timeoutable, or HasMaxExceptions, the corresponding job properties
     * are set from the operation's methods. This allows operations to define their own retry,
     * timeout, and failure handling strategies.
     *
     * @param Operation  $operation The operation instance to execute asynchronously. Encapsulates
     *                              the business logic and configuration for the queued work. The
     *                              operation's handle() method will be invoked within an optional
     *                              database transaction when the job is processed by the queue worker.
     * @param int|string $recordId  Primary key of the Operation model record for status tracking
     *                              and audit trail. Used to update execution timestamps (executed_at,
     *                              completed_at, failed_at) and record error details in the operations
     *                              table for monitoring and debugging purposes.
     */
    public function __construct(
        private readonly Operation $operation,
        private readonly int|string $recordId,
    ) {
        // Apply retry configuration from operation if it implements Retryable
        if ($operation instanceof Retryable) {
            $this->tries = $operation->tries();
            $backoff = $operation->backoff();
            $this->backoff = is_array($backoff) ? array_values($backoff) : $backoff;
            $this->retryUntil = $operation->retryUntil();
        }

        // Apply timeout configuration from operation if it implements Timeoutable
        if ($operation instanceof Timeoutable) {
            $this->timeout = $operation->timeout();
            $this->failOnTimeout = $operation->failOnTimeout();
        }

        // Apply max exceptions configuration from operation if it implements HasMaxExceptions
        if (!$operation instanceof HasMaxExceptions) {
            return;
        }

        $this->maxExceptions = $operation->maxExceptions();
    }

    /**
     * Get the middleware the job should pass through.
     *
     * Retrieves middleware stack from the operation if it implements HasMiddleware,
     * allowing operations to define their own job middleware for rate limiting,
     * logging, or custom behavior without modifying the job class.
     *
     * @return array<int, object> Array of middleware instances to apply to the job
     */
    public function middleware(): array
    {
        if ($this->operation instanceof HasMiddleware) {
            return array_values($this->operation->middleware());
        }

        return [];
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * Retrieves tags from the operation if it implements HasTags, enabling
     * operation-specific tagging for queue monitoring, filtering, and analytics
     * in tools like Laravel Horizon. Tags help identify and group related jobs.
     *
     * @return array<int, string> Array of tag strings for job identification
     */
    public function tags(): array
    {
        if ($this->operation instanceof HasTags) {
            return array_values($this->operation->tags());
        }

        return [];
    }

    /**
     * Get the unique ID for the job.
     *
     * Retrieves custom unique identifier from the operation if it implements
     * ShouldBeUnique, otherwise defaults to the operation record ID. Used by
     * Laravel's unique job implementation to prevent duplicate job execution.
     *
     * @return string Unique identifier for preventing duplicate job execution
     */
    public function uniqueId(): string
    {
        if ($this->operation instanceof ShouldBeUnique) {
            return $this->operation->uniqueId();
        }

        return (string) $this->recordId;
    }

    /**
     * Get the number of seconds the unique lock should be maintained.
     *
     * Retrieves lock duration from the operation if it implements ShouldBeUnique,
     * otherwise defaults to 3600 seconds (1 hour). The lock prevents duplicate jobs
     * with the same uniqueId from being processed for this duration.
     *
     * @return int Number of seconds to maintain the unique job lock
     */
    public function uniqueFor(): int
    {
        if ($this->operation instanceof ShouldBeUnique) {
            return $this->operation->uniqueFor();
        }

        return 3_600;
    }

    /**
     * Get the cache repository for unique lock.
     *
     * Retrieves custom cache repository from the operation if it implements
     * ShouldBeUnique. Allows operations to use different cache stores for
     * unique lock management (e.g., Redis for distributed systems).
     *
     * @return null|Repository Cache store for unique locks, null for default
     */
    public function uniqueVia(): ?Repository
    {
        if ($this->operation instanceof ShouldBeUnique) {
            return $this->operation->uniqueVia();
        }

        return null;
    }

    /**
     * Execute the queued operation.
     *
     * Retrieves the operation record, executes the operation within an optional database
     * transaction based on configuration and operation interfaces, then updates the record
     * with completion or failure timestamps. Errors are logged and rethrown to trigger
     * queue retry mechanisms.
     *
     * @throws Throwable When operation execution fails, after recording error details
     */
    public function handle(): void
    {
        $startTime = hrtime(true);
        $record = OperationModel::query()->findOrFail($this->recordId);

        Event::dispatch(
            new OperationStarted($this->operation, ExecutionMethod::Async),
        );

        // Execute before hook if operation implements HasLifecycleHooks
        if ($this->operation instanceof HasLifecycleHooks) {
            $this->operation->before();
        }

        $autoTransaction = config('sequencer.execution.auto_transaction', true);
        $useTransaction = $this->operation instanceof WithinTransaction || $autoTransaction;

        try {
            if ($useTransaction) {
                DB::transaction(fn () => $this->operation->handle());
            } else {
                $this->operation->handle();
            }

            $record->update(['completed_at' => Date::now()]);

            // Execute after hook if operation implements HasLifecycleHooks
            if ($this->operation instanceof HasLifecycleHooks) {
                $this->operation->after();
            }

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1e6);

            Event::dispatch(
                new OperationEnded($this->operation, ExecutionMethod::Async, $elapsedMs),
            );
        } catch (Throwable $throwable) {
            $record->update(['failed_at' => Date::now()]);

            $this->recordError($record, $throwable);

            // Execute failed hook if operation implements HasLifecycleHooks
            if ($this->operation instanceof HasLifecycleHooks) {
                $this->operation->failed($throwable);
            }

            throw $throwable;
        }
    }

    /**
     * Record operation error for audit trail and logging.
     *
     * Creates an OperationError record with exception details (class, message, trace, context)
     * and logs the failure to the configured log channel. Only executes if error recording
     * is enabled in configuration (sequencer.errors.record). Captures complete exception
     * context including file location, line number, and error code for debugging purposes.
     *
     * @param OperationModel $record    The operation record that failed execution. Used to link
     *                                  the error record to the specific operation for querying
     *                                  and debugging failed operations via Sequencer::getErrors().
     * @param Throwable      $exception The exception that caused the operation failure. All
     *                                  exception data (class, message, trace, file, line, code)
     *                                  is extracted and persisted to the operation_errors table
     *                                  for comprehensive failure analysis and debugging.
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
