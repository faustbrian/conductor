<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Retryable;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Jobs\ExecuteOperation;

beforeEach(function (): void {
    $this->operation = new class() implements Asynchronous, Operation, Retryable
    {
        public function tries(): int
        {
            return 5;
        }

        public function backoff(): array
        {
            return [1, 5, 10, 30, 60];
        }

        public function retryUntil(): ?DateTimeInterface
        {
            return now()->addHour();
        }

        public function handle(): void
        {
            // Test operation
        }
    };
});

test('retryable operation configures job with retry settings', function (): void {
    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
        'state' => OperationState::Pending,
    ]);

    $job = new ExecuteOperation($this->operation, $record->id);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([1, 5, 10, 30, 60])
        ->and($job->retryUntil)->toBeInstanceOf(DateTimeInterface::class);
});

test('operation without retryable interface has no retry config', function (): void {
    $operation = new class() implements Asynchronous, Operation
    {
        public function handle(): void {}
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
        'state' => OperationState::Pending,
    ]);

    $job = new ExecuteOperation($operation, $record->id);

    expect($job->tries)->toBeNull()
        ->and($job->backoff)->toBeNull()
        ->and($job->retryUntil)->toBeNull();
});

test('retryable operation with fixed backoff', function (): void {
    $operation = new class() implements Asynchronous, Operation, Retryable
    {
        public function tries(): int
        {
            return 3;
        }

        public function backoff(): int
        {
            return 30; // Fixed 30 second backoff
        }

        public function retryUntil(): ?DateTimeInterface
        {
            return null;
        }

        public function handle(): void {}
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
        'state' => OperationState::Pending,
    ]);

    $job = new ExecuteOperation($operation, $record->id);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(30);
});
