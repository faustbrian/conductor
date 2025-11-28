<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\HasTags;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Jobs\ExecuteOperation;

test('operation with HasTags returns tags array', function (): void {
    $operation = new class() implements Asynchronous, HasTags, Operation
    {
        public function tags(): array
        {
            return ['user-import', 'critical', 'batch-123'];
        }

        public function handle(): void {}
    };

    $record = Cline\Sequencer\Database\Models\Operation::query()->create([
        'name' => 'test_operation',
        'type' => 'async',
        'executed_at' => now(),
    ]);

    $job = new ExecuteOperation($operation, $record->id);

    expect($job->tags())->toBeArray()
        ->and($job->tags())->toHaveCount(3)
        ->and($job->tags())->toContain('user-import', 'critical', 'batch-123');
});

test('operation without HasTags returns empty array', function (): void {
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

    expect($job->tags())->toBeArray()
        ->and($job->tags())->toBeEmpty();
});
