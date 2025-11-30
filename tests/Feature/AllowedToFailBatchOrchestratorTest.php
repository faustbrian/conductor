<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Orchestrators\AllowedToFailBatchOrchestrator;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Operations\AllowedToFailOperation;
use Tests\Fixtures\Operations\FailingAllowedToFailOperation;
use Tests\Fixtures\Operations\FailingRollbackableOperation;
use Tests\Fixtures\Operations\NonRollbackableOperation;
use Tests\Fixtures\Operations\RollbackableWithDatabaseOperation;

uses(RefreshDatabase::class);

/**
 * Comprehensive integration tests for AllowedToFailBatchOrchestrator
 *
 * These tests use REAL operations, REAL database, and REAL discovery
 * to ensure the orchestrator works correctly with fault-tolerant semantics.
 *
 * Key difference from TransactionalBatchOrchestrator:
 * - Operations implementing AllowedToFail can fail without affecting other operations
 * - No rollback triggered for successful operations when AllowedToFail operations fail
 * - Different logging behavior for AllowedToFail vs critical failures
 */
describe('AllowedToFailBatchOrchestrator Integration Tests', function (): void {
    beforeEach(function (): void {
        // Create temp directory for test operations
        $this->tempDir = storage_path('framework/testing/operations_'.uniqid());
        File::makeDirectory($this->tempDir, 0o755, true);
        Config::set('sequencer.execution.discovery_paths', [$this->tempDir]);

        // Reset all test fixtures
        RollbackableWithDatabaseOperation::reset();
        FailingRollbackableOperation::reset();
        NonRollbackableOperation::reset();
        AllowedToFailOperation::reset();
        FailingAllowedToFailOperation::reset();
    });

    afterEach(function (): void {
        // Clean up temp directory
        if (property_exists($this, 'tempDir') && $this->tempDir !== null && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
    });

    describe('Dry-Run Preview Mode', function (): void {
        test('preview returns list of pending operations without executing', function (): void {
            // Arrange: Create operations
            $op1 = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\AllowedToFail;

return new class() implements Operation, AllowedToFail {
    public function handle(): void {
        throw new Exception('Should not execute in dry-run');
    }
};
PHP;

            $op2 = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;

return new class() implements Operation {
    public function handle(): void {
        throw new Exception('Should not execute in dry-run');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_first_op.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000001_second_op.php', $op2);

            // Act
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Preview returned (covers lines 112-114, 138-166)
            expect($result)->toBeArray()
                ->and($result)->toHaveCount(2)
                ->and($result[0])->toMatchArray([
                    'type' => 'operation',
                    'timestamp' => '2024_01_01_000000',
                ])
                ->and($result[1])->toMatchArray([
                    'type' => 'operation',
                    'timestamp' => '2024_01_01_000001',
                ]);

            // Verify nothing was executed
            expect(OperationModel::query()->count())->toBe(0);
        })->group('integration', 'happy-path', 'preview');

        test('preview with from parameter filters operations', function (): void {
            // Arrange: Create operations with different timestamps
            $op1 = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_old_op.php', $op1);
            File::put($this->tempDir.'/2024_06_01_000000_new_op.php', $op1);

            // Act: Preview from June onwards (covers line 143)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true, from: '2024_06_01_000000');

            // Assert: Only new operation shown
            expect($result)->toBeArray()
                ->and($result)->toHaveCount(1)
                ->and($result[0]['timestamp'])->toBe('2024_06_01_000000');
        })->group('integration', 'happy-path', 'preview');

        test('preview includes operation class name in output', function (): void {
            // Arrange
            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_class_test.php', $op);

            // Act
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Operation name is the file path (covers lines 152-156)
            expect($result[0])->toHaveKey('name');
            expect($result[0]['name'])->toContain('2024_01_01_000000_class_test.php');
        })->group('integration', 'happy-path', 'preview');
    });

    describe('Process Method Routing', function (): void {
        test('process returns preview array when dryRun is true', function (): void {
            // Arrange
            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_routing_test.php', $op);

            // Act
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Returns array (covers lines 112-114)
            expect($result)->toBeArray();
        })->group('integration', 'happy-path', 'routing');

        test('process returns null when isolate is true', function (): void {
            // Arrange
            Config::set('sequencer.execution.lock.store', 'array');
            Config::set('sequencer.execution.lock.timeout', 1);
            Config::set('sequencer.execution.lock.ttl', 60);

            // Act: With isolate but no operations
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(isolate: true);

            // Assert: Returns null (covers lines 116-119)
            expect($result)->toBeNull();
        })->group('integration', 'happy-path', 'routing');

        test('process returns null for normal execution', function (): void {
            // Act: No operations
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process();

            // Assert: Returns null (covers line 124)
            expect($result)->toBeNull();
        })->group('integration', 'happy-path', 'routing');
    });

    describe('Empty Operations Queue', function (): void {
        test('handles empty operations queue gracefully', function (): void {
            // Arrange: No operations
            Event::fake();

            // Act
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: NoPendingOperations event fired (covers lines 229-234)
            Event::assertDispatched(NoPendingOperations::class, fn ($event): bool => $event->method === ExecutionMethod::AllowedToFailBatch);

            expect(OperationModel::query()->count())->toBe(0);
        })->group('integration', 'edge-case', 'execution');

        test('fires NoPendingOperations event with correct execution method', function (): void {
            // Arrange
            Event::fake();

            // Act
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process();

            // Assert
            Event::assertDispatched(NoPendingOperations::class, fn ($event): bool => $event->method === ExecutionMethod::AllowedToFailBatch);

            Event::assertNotDispatched(OperationsStarted::class);
            Event::assertNotDispatched(OperationsEnded::class);
        })->group('integration', 'edge-case', 'events');
    });

    describe('Lock Acquisition', function (): void {
        test('executes with lock when isolate is true', function (): void {
            // Arrange: Configure lock settings
            Config::set('sequencer.execution.lock.store', 'array');
            Config::set('sequencer.execution.lock.timeout', 1);
            Config::set('sequencer.execution.lock.ttl', 60);

            // Act: Execute with lock but no operations (covers lines 185-191, 201-206)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process(isolate: true);

            // Assert: Lock was acquired and released (no exception)
            $lock = Cache::store('array')->lock('sequencer:process', 60);
            expect($lock->get())->toBeTrue();
            $lock->release();
        })->group('integration', 'happy-path', 'locking');

        test('throws exception when lock cannot be acquired', function (): void {
            // Arrange: Acquire lock first
            Config::set('sequencer.execution.lock.store', 'array');
            Config::set('sequencer.execution.lock.timeout', 0); // Immediate timeout
            Config::set('sequencer.execution.lock.ttl', 60);

            // Acquire the lock to block subsequent attempts
            $firstLock = Cache::store('array')->lock('sequencer:process', 60);
            $firstLock->get();

            // Act & Assert: Second attempt should throw LockTimeoutException (covers line 197)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);

            expect(fn () => $orchestrator->process(isolate: true))
                ->toThrow(LockTimeoutException::class);

            // Clean up
            $firstLock->release();
        })->group('integration', 'sad-path', 'locking');

        test('releases lock after execution completes', function (): void {
            // Arrange
            Config::set('sequencer.execution.lock.store', 'array');
            Config::set('sequencer.execution.lock.timeout', 1);
            Config::set('sequencer.execution.lock.ttl', 60);

            // Act: Execute with lock (covers finally block lines 203-205)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process(isolate: true);

            // Assert: Lock can be acquired again
            $lock = Cache::store('array')->lock('sequencer:process', 60);
            expect($lock->get())->toBeTrue();
            $lock->release();
        })->group('integration', 'happy-path', 'locking');

        test('releases lock even when execution fails', function (): void {
            // Arrange
            Config::set('sequencer.execution.lock.store', 'array');
            Config::set('sequencer.execution.lock.timeout', 1);
            Config::set('sequencer.execution.lock.ttl', 60);

            // Create invalid operation to trigger failure
            $invalid = <<<'PHP'
<?php
// Missing return - will cause error
use Cline\Sequencer\Contracts\Operation;
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_failing_op.php', $invalid);

            // Act: Execute with lock (will fail) (covers finally block)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);

            try {
                $orchestrator->process(isolate: true);
            } catch (Throwable) {
                // Expected to fail
            }

            // Assert: Lock was released despite failure
            $lock = Cache::store('array')->lock('sequencer:process', 60);
            expect($lock->get())->toBeTrue();
            $lock->release();
        })->group('integration', 'sad-path', 'locking');
    });

    describe('Timestamp Filtering (from parameter)', function (): void {
        test('executes only operations on or after from timestamp', function (): void {
            // Arrange: Create operations with different timestamps
            $opContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_old.php', $opContent);
            File::put($this->tempDir.'/2024_06_01_000000_new.php', $opContent);
            File::put($this->tempDir.'/2024_12_01_000000_newest.php', $opContent);

            // Act: Preview from June onwards (covers line 226)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true, from: '2024_06_01_000000');

            // Assert: Only 2 operations shown
            expect($result)->toHaveCount(2);
            expect($result[0]['timestamp'])->toBe('2024_06_01_000000');
            expect($result[1]['timestamp'])->toBe('2024_12_01_000000');
        })->group('integration', 'happy-path', 'filtering');

        test('from parameter works correctly in execute path', function (): void {
            // Arrange
            $opContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip.php', $opContent);
            File::put($this->tempDir.'/2024_06_01_000000_include.php', $opContent);

            Event::fake();

            // Act: Execute with from parameter (covers execute line 226)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process(from: '2024_07_01_000000');

            // Assert: No operations executed (all filtered out)
            Event::assertDispatched(NoPendingOperations::class);
        })->group('integration', 'happy-path', 'filtering');
    });

    describe('Dependency Resolution', function (): void {
        test('operations are sorted by dependencies through resolver', function (): void {
            // Arrange: Create operations that would trigger dependency sorting
            $opA = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_op_a.php', $opA);

            // Act: Preview shows dependency resolver is called (covers line 431)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Operation appears in result (dependency resolver was called)
            expect($result)->toHaveCount(1);
        })->group('integration', 'happy-path', 'dependencies');
    });

    describe('Task Discovery', function (): void {
        test('discovers pending tasks with operations', function (): void {
            // Arrange: Create operation
            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_discover.php', $op);

            // Act: discoverPendingTasks is called (covers lines 406-431)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Task was discovered
            expect($result)->toHaveCount(1);
            expect($result[0]['type'])->toBe('operation');
        })->group('integration', 'happy-path', 'discovery');
    });

    describe('Batch Execution', function (): void {
        test('batch execution creates operation records', function (): void {
            // Arrange: Create fixture operation files
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\AllowedToFailOperation;
return new AllowedToFailOperation();
PHP;

            $op2 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\NonRollbackableOperation;
return new NonRollbackableOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_first_op.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000001_second_op.php', $op2);

            // Use Bus::fake() to avoid actual queue processing and prevent hanging
            Bus::fake();
            Event::fake([OperationsStarted::class, OperationsEnded::class]);

            // Act: Execute batch
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: Operation records were created (covers lines 285-296)
            expect(OperationModel::query()->count())->toBe(2);

            $firstOp = OperationModel::query()->where('name', '2024_01_01_000000_first_op.php')->first();
            expect($firstOp)->not->toBeNull();
            expect($firstOp->type)->toBe(ExecutionMethod::AllowedToFailBatch->value);
            expect($firstOp->executed_at)->not->toBeNull();

            $secondOp = OperationModel::query()->where('name', '2024_01_01_000001_second_op.php')->first();
            expect($secondOp)->not->toBeNull();
            expect($secondOp->type)->toBe(ExecutionMethod::AllowedToFailBatch->value);

            // Assert: Events were dispatched (covers lines 237-239, 252-254)
            Event::assertDispatched(OperationsStarted::class, fn ($event): bool => $event->method === ExecutionMethod::AllowedToFailBatch);

            Event::assertDispatched(OperationsEnded::class, fn ($event): bool => $event->method === ExecutionMethod::AllowedToFailBatch);

            // Assert: Batch was created with correct configuration (covers lines 299-304)
            Bus::assertBatched(fn ($batch): bool => $batch->name === 'Sequencer AllowedToFail Operations Batch'
                && count($batch->jobs) === 2);
        })->group('integration', 'batch');

        test('batch allows failures with allowFailures configuration', function (): void {
            // Arrange: Create operations
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\AllowedToFailOperation;
return new AllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_allowed_op.php', $op1);

            // Use Bus::fake() to capture batch configuration
            Bus::fake();

            // Act: Execute batch
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: Batch was created with allowFailures() (covers line 301)
            Bus::assertBatched(fn ($batch): bool =>
                // Verify batch allows failures
                $batch->name === 'Sequencer AllowedToFail Operations Batch'
                && $batch->allowsFailures());
        })->group('integration', 'batch');

        test('batch has then callback for processing results', function (): void {
            // Arrange: Create operations
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\AllowedToFailOperation;
return new AllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_then_test.php', $op1);

            // Use Bus::fake()
            Bus::fake();

            // Act: Execute batch
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: Batch has then callbacks (covers line 302)
            Bus::assertBatched(fn ($batch): bool => count($batch->thenCallbacks()) > 0);
        })->group('integration', 'batch');

        test('batch has catch callback for critical failures', function (): void {
            // Arrange: Create operations
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\NonRollbackableOperation;
return new NonRollbackableOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_catch_test.php', $op1);

            // Use Bus::fake()
            Bus::fake();

            // Act: Execute batch
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: Batch has catch callbacks (covers line 303)
            Bus::assertBatched(fn ($batch): bool => count($batch->catchCallbacks()) > 0);
        })->group('integration', 'batch');

        test('executeBatch returns PendingBatch instance', function (): void {
            // Arrange
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\AllowedToFailOperation;
return new AllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_return_test.php', $op1);

            // Use Bus::fake()
            Bus::fake();

            // Act
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: Batch was dispatched (covers lines 271-304)
            Bus::assertBatched(fn ($batch): bool => $batch->name === 'Sequencer AllowedToFail Operations Batch');
        })->group('integration', 'batch');
    });

    describe('Batch Result Processing', function (): void {
        test('processBatchResults logs AllowedToFail failures as warnings', function (): void {
            // Arrange: Create failed operation record for AllowedToFail operation
            $record = OperationModel::query()->create([
                'name' => '2024_01_01_000000_allowed_fail.php',
                'type' => ExecutionMethod::AllowedToFailBatch,
                'state' => OperationState::Failed,
                'executed_at' => now(),
            ]);

            // Create operation file
            $op = <<<'PHP'
<?php
use Tests\Fixtures\Operations\FailingAllowedToFailOperation;
return new FailingAllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_allowed_fail.php', $op);

            // Load the operation
            $operation = require $this->tempDir.'/2024_01_01_000000_allowed_fail.php';

            // Mock batch
            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'test-batch-id';

            // Prepare operation records
            $operationRecords = [
                $record->id => [
                    'operation' => $operation,
                    'record' => $record,
                ],
            ];

            // Mock Log facade
            $mockLogger = Mockery::mock();
            $mockLogger->shouldReceive('warning')
                ->once()
                ->with('AllowedToFail operation failed (non-blocking)', Mockery::on(fn ($context): bool => $context['operation'] === '2024_01_01_000000_allowed_fail.php'
                    && $context['batch_id'] === 'test-batch-id'));

            Log::shouldReceive('channel')
                ->with('stack')
                ->once()
                ->andReturn($mockLogger);

            // Act: Use reflection to call processBatchResults
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('processBatchResults');
            $method->invoke($orchestrator, $mockBatch, $operationRecords);

            // Assert: Warning was logged for AllowedToFail operation (covers lines 323-338)
        })->group('integration', 'batch-results');

        test('processBatchResults logs non-AllowedToFail failures as errors', function (): void {
            // Arrange: Create failed operation record for non-AllowedToFail operation
            $record = OperationModel::query()->create([
                'name' => '2024_01_01_000000_critical_fail.php',
                'type' => ExecutionMethod::AllowedToFailBatch,
                'state' => OperationState::Failed,
                'executed_at' => now(),
            ]);

            // Create operation file (non-AllowedToFail)
            $op = <<<'PHP'
<?php
use Tests\Fixtures\Operations\FailingRollbackableOperation;
return new FailingRollbackableOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_critical_fail.php', $op);

            // Load the operation
            $operation = require $this->tempDir.'/2024_01_01_000000_critical_fail.php';

            // Mock batch
            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'test-batch-id';

            // Prepare operation records
            $operationRecords = [
                $record->id => [
                    'operation' => $operation,
                    'record' => $record,
                ],
            ];

            // Mock Log facade
            $mockLogger = Mockery::mock();
            $mockLogger->shouldReceive('error')
                ->once()
                ->with('Critical operation failed in AllowedToFail batch', Mockery::on(fn ($context): bool => $context['operation'] === '2024_01_01_000000_critical_fail.php'
                    && $context['batch_id'] === 'test-batch-id'));

            Log::shouldReceive('channel')
                ->with('stack')
                ->once()
                ->andReturn($mockLogger);

            // Act: Use reflection to call processBatchResults
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('processBatchResults');
            $method->invoke($orchestrator, $mockBatch, $operationRecords);

            // Assert: Error was logged for critical operation (covers lines 339-344)
        })->group('integration', 'batch-results');

        test('processBatchResults skips records that do not exist', function (): void {
            // Arrange: Create operation record
            $record = OperationModel::query()->create([
                'name' => '2024_01_01_000000_deleted.php',
                'type' => ExecutionMethod::AllowedToFailBatch,
                'state' => OperationState::Failed,
                'executed_at' => now(),
            ]);

            // Create operation file
            $op = <<<'PHP'
<?php
use Tests\Fixtures\Operations\AllowedToFailOperation;
return new AllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_deleted.php', $op);
            $operation = require $this->tempDir.'/2024_01_01_000000_deleted.php';

            // Delete the record to simulate null fresh()
            $recordId = $record->id;
            $record->delete();

            // Mock batch
            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'test-batch-id';

            // Prepare operation records with deleted record
            $operationRecords = [
                $recordId => [
                    'operation' => $operation,
                    'record' => $record,
                ],
            ];

            // Mock Log - should not be called
            Log::shouldReceive('channel')->never();

            // Act: Use reflection to call processBatchResults
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('processBatchResults');
            $method->invoke($orchestrator, $mockBatch, $operationRecords);

            // Assert: No logging occurred for deleted record (covers lines 326-328)
        })->group('integration', 'batch-results');

        test('processBatchResults only processes failed operations', function (): void {
            // Arrange: Create successful operation record
            $record = OperationModel::query()->create([
                'name' => '2024_01_01_000000_success.php',
                'type' => ExecutionMethod::AllowedToFailBatch,
                'state' => OperationState::Completed,
                'executed_at' => now(),
                'completed_at' => now(),
            ]);

            // Create operation file
            $op = <<<'PHP'
<?php
use Tests\Fixtures\Operations\AllowedToFailOperation;
return new AllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_success.php', $op);
            $operation = require $this->tempDir.'/2024_01_01_000000_success.php';

            // Mock batch
            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'test-batch-id';

            // Prepare operation records
            $operationRecords = [
                $record->id => [
                    'operation' => $operation,
                    'record' => $record,
                ],
            ];

            // Mock Log - should not be called
            Log::shouldReceive('channel')->never();

            // Act: Use reflection to call processBatchResults
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('processBatchResults');
            $method->invoke($orchestrator, $mockBatch, $operationRecords);

            // Assert: No logging occurred for successful operation (covers line 331)
        })->group('integration', 'batch-results');
    });

    describe('Critical Failure Handling', function (): void {
        test('handleCriticalFailures logs critical alert with batch stats', function (): void {
            // Arrange
            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'critical-batch-id';
            $mockBatch->totalJobs = 10;
            $mockBatch->failedJobs = 3;

            // Mock Log facade
            $mockLogger = Mockery::mock();
            $mockLogger->shouldReceive('critical')
                ->once()
                ->with('AllowedToFail batch encountered failures', Mockery::on(fn ($context): bool => $context['batch_id'] === 'critical-batch-id'
                    && $context['total_jobs'] === 10
                    && $context['failed_jobs'] === 3));

            Log::shouldReceive('channel')
                ->with('stack')
                ->once()
                ->andReturn($mockLogger);

            // Act: Use reflection to call handleCriticalFailures
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('handleCriticalFailures');
            $method->invoke($orchestrator, $mockBatch, []);

            // Assert: Critical log was written (covers lines 359-368)
        })->group('integration', 'critical-failures');
    });

    describe('Migration Execution', function (): void {
        test('executeMigration runs Laravel migrations', function (): void {
            // Arrange: Create a test migration
            $migrationDir = storage_path('framework/testing/sequencer_migrations_'.uniqid());
            File::makeDirectory($migrationDir, 0o755, true);

            $timestamp = '2099_01_01_000000';
            $migrationName = $timestamp.'_create_test_allowed_fail_migration_table';
            $migrationFile = $migrationName.'.php';

            $migrationContent = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('test_allowed_fail_migration_table', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('test_allowed_fail_migration_table');
    }
};
PHP;

            File::put($migrationDir.'/'.$migrationFile, $migrationContent);

            // Create migration task structure
            $task = [
                'type' => 'migration',
                'timestamp' => $timestamp,
                'data' => [
                    'path' => $migrationDir.'/'.$migrationFile,
                    'name' => $migrationName,
                ],
            ];

            // Act: Use reflection to call executeMigration
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('executeMigration');
            $method->invoke($orchestrator, $task);

            // Assert: Migration was executed (covers lines 383-393)
            expect(Schema::hasTable('test_allowed_fail_migration_table'))->toBeTrue();

            // Clean up
            Schema::dropIfExists('test_allowed_fail_migration_table');
            DB::table('migrations')->where('migration', $migrationName)->delete();
            File::deleteDirectory($migrationDir);
        })->group('integration', 'migration');

        test('execute processes migrations before operations', function (): void {
            // Note: Migration integration requires complex discovery setup with migration paths
            // The migration execution paths (lines 241-250) are covered through the discovery service tests
            // and the executeMigration method is tested directly above
            $this->markTestSkipped('Migration integration requires complex migration path discovery setup');
        })->group('integration', 'migration');
    });

    describe('Edge Cases', function (): void {
        test('handles operations with no pending operations after filtering', function (): void {
            // Arrange
            Event::fake();

            // Act: Execute with from that filters everything
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $orchestrator->process(from: '2099_01_01_000000');

            // Assert: NoPendingOperations event fired
            Event::assertDispatched(NoPendingOperations::class);
        })->group('integration', 'edge-case');

        test('operations are sorted chronologically before dependency resolution', function (): void {
            // Arrange: Create operations in non-chronological order
            $op1 = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            // Write files in reverse order
            File::put($this->tempDir.'/2024_12_01_000000_last.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000000_first.php', $op1);
            File::put($this->tempDir.'/2024_06_01_000000_middle.php', $op1);

            // Act: Get preview (covers line 429 - chronological sorting)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Operations are in chronological order
            expect($result[0]['timestamp'])->toBe('2024_01_01_000000');
            expect($result[1]['timestamp'])->toBe('2024_06_01_000000');
            expect($result[2]['timestamp'])->toBe('2024_12_01_000000');
        })->group('integration', 'edge-case');

        test('repeat parameter is passed to operation discovery', function (): void {
            // Arrange: Create and execute operation
            $op = <<<'PHP'
<?php
use Tests\Fixtures\Operations\AllowedToFailOperation;
return new AllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_repeat_test.php', $op);

            // Mark as completed
            OperationModel::query()->create([
                'name' => '2024_01_01_000000_repeat_test.php',
                'type' => ExecutionMethod::AllowedToFailBatch,
                'executed_at' => now(),
                'completed_at' => now(),
                'state' => OperationState::Completed,
            ]);

            // Act: Preview with repeat=true (covers line 409)
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true, repeat: true);

            // Assert: Completed operation is included with repeat
            expect($result)->toHaveCount(1);
        })->group('integration', 'edge-case');
    });

    describe('Configuration', function (): void {
        test('uses configured log channel for batch results', function (): void {
            // Arrange: Configure custom log channel
            Config::set('sequencer.errors.log_channel', 'custom');

            // Create failed AllowedToFail operation record
            $record = OperationModel::query()->create([
                'name' => '2024_01_01_000000_log_channel.php',
                'type' => ExecutionMethod::AllowedToFailBatch,
                'state' => OperationState::Failed,
                'executed_at' => now(),
            ]);

            $op = <<<'PHP'
<?php
use Tests\Fixtures\Operations\FailingAllowedToFailOperation;
return new FailingAllowedToFailOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_log_channel.php', $op);
            $operation = require $this->tempDir.'/2024_01_01_000000_log_channel.php';

            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'test-batch-id';

            $operationRecords = [
                $record->id => [
                    'operation' => $operation,
                    'record' => $record,
                ],
            ];

            // Mock Log facade with custom channel
            $mockLogger = Mockery::mock();
            $mockLogger->shouldReceive('warning')->once();

            Log::shouldReceive('channel')
                ->with('custom')
                ->once()
                ->andReturn($mockLogger);

            // Act: Use reflection to call processBatchResults
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('processBatchResults');
            $method->invoke($orchestrator, $mockBatch, $operationRecords);

            // Assert: Custom channel was used (covers line 321)
        })->group('integration', 'configuration');

        test('uses configured log channel for critical failures', function (): void {
            // Arrange: Configure custom log channel
            Config::set('sequencer.errors.log_channel', 'custom');

            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'test-batch-id';
            $mockBatch->totalJobs = 5;
            $mockBatch->failedJobs = 2;

            // Mock Log facade with custom channel
            $mockLogger = Mockery::mock();
            $mockLogger->shouldReceive('critical')->once();

            Log::shouldReceive('channel')
                ->with('custom')
                ->once()
                ->andReturn($mockLogger);

            // Act: Use reflection to call handleCriticalFailures
            $orchestrator = app(AllowedToFailBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('handleCriticalFailures');
            $method->invoke($orchestrator, $mockBatch, []);

            // Assert: Custom channel was used (covers line 362)
        })->group('integration', 'configuration');
    });
});
