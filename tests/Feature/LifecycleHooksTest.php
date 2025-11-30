<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\HasLifecycleHooks;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Tests\Exceptions\SimulatedFailureException;

test('lifecycle hooks execute in correct order for successful operation', function (): void {
    $executionOrder = [];

    $operation = new class($executionOrder) implements Asynchronous, HasLifecycleHooks, Operation
    {
        public function __construct(
            public array &$executionOrder,
        ) {}

        public function before(): void
        {
            $this->executionOrder[] = 'before';
        }

        public function handle(): void
        {
            $this->executionOrder[] = 'handle';
        }

        public function after(): void
        {
            $this->executionOrder[] = 'after';
        }

        public function failed(Throwable $exception): void
        {
            $this->executionOrder[] = 'failed';
        }
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
        'state' => OperationState::Pending,
    ]);

    $job = new ExecuteOperation($operation, $record->id);
    $job->handle();

    expect($executionOrder)->toBe(['before', 'handle', 'after']);
});

test('failed hook executes when operation throws exception', function (): void {
    $executionOrder = [];
    $caughtException = null;

    $operation = new class($executionOrder, $caughtException) implements Asynchronous, HasLifecycleHooks, Operation
    {
        public function __construct(
            public array &$executionOrder,
            public &$caughtException,
        ) {}

        public function before(): void
        {
            $this->executionOrder[] = 'before';
        }

        public function handle(): void
        {
            $this->executionOrder[] = 'handle';

            throw SimulatedFailureException::operationFailed();
        }

        public function after(): void
        {
            $this->executionOrder[] = 'after';
        }

        public function failed(Throwable $exception): void
        {
            $this->executionOrder[] = 'failed';
            $this->caughtException = $exception;
        }
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
        'state' => OperationState::Pending,
    ]);

    $job = new ExecuteOperation($operation, $record->id);

    try {
        $job->handle();
    } catch (SimulatedFailureException) {
        // Expected
    }

    expect($executionOrder)->toBe(['before', 'handle', 'failed'])
        ->and($caughtException)->toBeInstanceOf(SimulatedFailureException::class)
        ->and($caughtException->getMessage())->toBe('Operation failed');
});

test('operation without lifecycle hooks executes normally', function (): void {
    $operation = new class() implements Asynchronous, Operation
    {
        public function handle(): void
        {
            // No hooks
        }
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
        'state' => OperationState::Pending,
    ]);

    $job = new ExecuteOperation($operation, $record->id);
    $job->handle();

    expect($record->fresh()->completed_at)->not->toBeNull();
});
