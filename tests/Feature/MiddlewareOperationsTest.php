<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\HasMiddleware;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Illuminate\Queue\Middleware\RateLimited;

test('operation with middleware returns middleware array', function (): void {
    $operation = new class() implements Asynchronous, HasMiddleware, Operation
    {
        public function middleware(): array
        {
            return [new RateLimited('test')];
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

    expect($job->middleware())->toBeArray()
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class);
});

test('operation without middleware returns empty array', function (): void {
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

    expect($job->middleware())->toBeArray()
        ->and($job->middleware())->toBeEmpty();
});
