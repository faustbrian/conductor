<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\HasMaxExceptions;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Jobs\ExecuteOperation;

test('operation with HasMaxExceptions configures job', function (): void {
    $operation = new class() implements Asynchronous, HasMaxExceptions, Operation
    {
        public function maxExceptions(): int
        {
            return 3;
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

    expect($job->maxExceptions)->toBe(3);
});

test('operation without HasMaxExceptions has null maxExceptions', function (): void {
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

    expect($job->maxExceptions)->toBeNull();
});
