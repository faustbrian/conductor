<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Timeoutable;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Jobs\ExecuteOperation;

test('timeoutable operation configures job with timeout settings', function (): void {
    $operation = new class() implements Asynchronous, Operation, Timeoutable
    {
        public function timeout(): int
        {
            return 120;
        }

        public function failOnTimeout(): bool
        {
            return true;
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

    expect($job->timeout)->toBe(120)
        ->and($job->failOnTimeout)->toBeTrue();
});

test('operation without timeoutable interface has no timeout config', function (): void {
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

    expect($job->timeout)->toBeNull()
        ->and($job->failOnTimeout)->toBeFalse();
});

test('timeoutable operation with retry on timeout', function (): void {
    $operation = new class() implements Asynchronous, Operation, Timeoutable
    {
        public function timeout(): int
        {
            return 60;
        }

        public function failOnTimeout(): bool
        {
            return false; // Allow retry
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

    expect($job->timeout)->toBe(60)
        ->and($job->failOnTimeout)->toBeFalse();
});
