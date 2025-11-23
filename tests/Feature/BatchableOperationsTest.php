<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Concerns\Batchable;
use Cline\Sequencer\Contracts\Operation;
use Illuminate\Bus\Batch;

test('batchable trait provides batch access', function (): void {
    $operation = new class() implements Operation
    {
        use Batchable;

        public function handle(): void {}
    };

    expect($operation->batch())->toBeNull()
        ->and($operation->batching())->toBeFalse();
});

test('batchable trait can set batch context', function (): void {
    $operation = new class() implements Operation
    {
        use Batchable;

        public function handle(): void {}
    };

    $batch = Mockery::mock(Batch::class);
    $operation->withBatch($batch);

    expect($operation->batch())->toBe($batch)
        ->and($operation->batching())->toBeTrue();
});

test('batchable trait returns self from withBatch', function (): void {
    $operation = new class() implements Operation
    {
        use Batchable;

        public function handle(): void {}
    };

    $batch = Mockery::mock(Batch::class);
    $result = $operation->withBatch($batch);

    expect($result)->toBe($operation);
});

test('addBatch returns null when not in batch context', function (): void {
    $operation = new class() implements Operation
    {
        use Batchable;

        public function handle(): void {}
    };

    $result = $operation->addBatch([]);

    expect($result)->toBeNull();
});
