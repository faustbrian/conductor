<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\ShouldBeEncrypted;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted as LaravelShouldBeEncrypted;

test('operation implementing ShouldBeEncrypted makes job encrypted', function (): void {
    $operation = new class() implements Asynchronous, Operation, ShouldBeEncrypted
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

    expect($job)->toBeInstanceOf(LaravelShouldBeEncrypted::class);
});

test('operation without ShouldBeEncrypted still creates encrypted job', function (): void {
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

    // ExecuteOperation always implements LaravelShouldBeEncrypted
    expect($job)->toBeInstanceOf(LaravelShouldBeEncrypted::class);
});
