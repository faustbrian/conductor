<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\ShouldBeUnique;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

test('unique operation provides unique id', function (): void {
    $operation = new class() implements Asynchronous, Operation, ShouldBeUnique
    {
        public function uniqueId(): string
        {
            return 'unique-operation-123';
        }

        public function uniqueFor(): int
        {
            return 3_600;
        }

        public function uniqueVia(): ?Repository
        {
            return null;
        }

        public function handle(): void {}
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
    ]);

    $job = new ExecuteOperation($operation, $record->id);

    expect($job->uniqueId())->toBe('unique-operation-123')
        ->and($job->uniqueFor())->toBe(3_600)
        ->and($job->uniqueVia())->toBeNull();
});

test('non-unique operation uses record id as unique id', function (): void {
    $operation = new class() implements Asynchronous, Operation
    {
        public function handle(): void {}
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
    ]);

    $job = new ExecuteOperation($operation, $record->id);

    expect($job->uniqueId())->toBe((string) $record->id)
        ->and($job->uniqueFor())->toBe(3_600)
        ->and($job->uniqueVia())->toBeNull();
});

test('unique operation can specify custom cache driver', function (): void {
    $operation = new class() implements Asynchronous, Operation, ShouldBeUnique
    {
        public function uniqueId(): string
        {
            return 'unique-operation-456';
        }

        public function uniqueFor(): int
        {
            return 7_200;
        }

        public function uniqueVia(): ?Repository
        {
            return Cache::driver('array');
        }

        public function handle(): void {}
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
    ]);

    $job = new ExecuteOperation($operation, $record->id);

    expect($job->uniqueVia())->toBeInstanceOf(Repository::class);
});
