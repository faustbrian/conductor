<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Exceptions\CircularDependencyException;
use Cline\Sequencer\Orchestrators\DependencyGraphOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

describe('DependencyGraphOrchestrator Integration Tests', function (): void {
    beforeEach(function (): void {
        $this->tempDir = storage_path('framework/testing/depgraph_'.uniqid());
        File::makeDirectory($this->tempDir, 0o755, true);
        Config::set('sequencer.execution.discovery_paths', [$this->tempDir]);

        Sleep::fake();
        Bus::fake();
    });

    afterEach(function (): void {
        if (property_exists($this, 'tempDir') && $this->tempDir !== null && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
    });

    describe('Dry-Run Preview Mode', function (): void {
        test('preview returns list of pending operations without executing', function (): void {
            $op1 = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_op1.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000001_op2.php', $op1);

            $orchestrator = app(DependencyGraphOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            expect($result)->toBeArray()
                ->and($result)->toHaveCount(2)
                ->and(OperationModel::query()->count())->toBe(0);
        })->group('happy-path');

        test('preview includes operation names', function (): void {
            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_test_op.php', $op);

            $orchestrator = app(DependencyGraphOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            expect($result[0])->toHaveKey('name')
                ->and($result[0]['name'])->toContain('test_op');
        })->group('happy-path');
    });

    describe('Wave Execution', function (): void {
        test('executes operations and creates records', function (): void {
            $this->markTestSkipped('Wave execution uses blocking while loop that requires batch callbacks to execute. Cannot test with Bus::fake() as callbacks never fire.');

            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_wave_op.php', $op);

            Event::fake();

            $orchestrator = app(DependencyGraphOrchestrator::class);
            $orchestrator->process();

            // Batch dispatched with then/catch callbacks
            Bus::assertBatched(fn ($batch): bool => $batch->name === 'Sequencer Dependency Graph Wave 1');

            // Operation record created
            expect(OperationModel::query()->count())->toBe(1);
            expect(OperationModel::query()->first()->type)->toBe(ExecutionMethod::DependencyGraph);

            // Events dispatched
            Event::assertDispatched(OperationsStarted::class);
            Event::assertDispatched(OperationsEnded::class);
        })->group('integration');

        test('batch has then callback configured', function (): void {
            $this->markTestSkipped('Wave execution uses blocking while loop that requires batch callbacks to execute. Cannot test with Bus::fake() as callbacks never fire.');

            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_callback_test.php', $op);

            $orchestrator = app(DependencyGraphOrchestrator::class);
            $orchestrator->process();

            Bus::assertBatched(fn ($batch): bool => $batch->name === 'Sequencer Dependency Graph Wave 1'
                && $batch->thenCallbacks()->isNotEmpty());
        })->group('integration');

        test('batch has catch callback configured', function (): void {
            $this->markTestSkipped('Wave execution uses blocking while loop that requires batch callbacks to execute. Cannot test with Bus::fake() as callbacks never fire.');

            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_catch_test.php', $op);

            $orchestrator = app(DependencyGraphOrchestrator::class);
            $orchestrator->process();

            Bus::assertBatched(fn ($batch): bool => $batch->name === 'Sequencer Dependency Graph Wave 1'
                && $batch->catchCallbacks()->isNotEmpty());
        })->group('integration');
    });

    describe('Circular Dependency Detection', function (): void {
        test('throws exception when circular dependency detected', function (): void {
            $this->markTestSkipped('Circular dependency detection happens during wave execution which cannot be tested with Bus::fake()');

            // Create two operations that depend on each other
            $opA = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\HasDependencies;

return new class() implements Operation, HasDependencies {
    public function handle(): void {}
    public function dependsOn(): array {
        return ['2024_01_01_000001_circular_b.php'];
    }
};
PHP;

            $opB = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\HasDependencies;

return new class() implements Operation, HasDependencies {
    public function handle(): void {}
    public function dependsOn(): array {
        return ['2024_01_01_000000_circular_a.php'];
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_circular_a.php', $opA);
            File::put($this->tempDir.'/2024_01_01_000001_circular_b.php', $opB);

            $orchestrator = app(DependencyGraphOrchestrator::class);

            expect(fn () => $orchestrator->process())
                ->toThrow(CircularDependencyException::class);
        })->group('sad-path');
    });

    describe('Empty Operations Queue', function (): void {
        test('handles empty queue gracefully', function (): void {
            Event::fake();

            $orchestrator = app(DependencyGraphOrchestrator::class);
            $orchestrator->process();

            Event::assertDispatched(NoPendingOperations::class, fn ($event): bool => $event->method === ExecutionMethod::DependencyGraph);
        })->group('edge-case');
    });
});
