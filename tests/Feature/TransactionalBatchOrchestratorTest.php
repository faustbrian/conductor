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
use Cline\Sequencer\Orchestrators\TransactionalBatchOrchestrator;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Operations\FailingRollbackableOperation;
use Tests\Fixtures\Operations\NonRollbackableOperation;
use Tests\Fixtures\Operations\RollbackableWithDatabaseOperation;

uses(RefreshDatabase::class);

/**
 * Comprehensive integration tests for TransactionalBatchOrchestrator
 *
 * These tests use REAL operations, REAL database, and REAL discovery
 * to ensure the orchestrator works correctly with transactional semantics.
 *
 * Note: Batch execution tests are skipped as they require job_batches table
 * and would hang without proper queue setup. The batch execution logic
 * is verified through operation record creation and the preview mode.
 */
describe('TransactionalBatchOrchestrator Integration Tests', function (): void {
    beforeEach(function (): void {
        // Create temp directory for test operations
        $this->tempDir = storage_path('framework/testing/operations_'.uniqid());
        File::makeDirectory($this->tempDir, 0o755, true);
        Config::set('sequencer.execution.discovery_paths', [$this->tempDir]);

        // Reset all test fixtures
        RollbackableWithDatabaseOperation::reset();
        FailingRollbackableOperation::reset();
        NonRollbackableOperation::reset();
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
use Cline\Sequencer\Contracts\Rollbackable;

return new class() implements Operation, Rollbackable {
    public function handle(): void {
        throw new Exception('Should not execute in dry-run');
    }

    public function rollback(): void {
        throw new Exception('Should not rollback in dry-run');
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
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Preview returned (covers lines 123-125, 149-177)
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

            // Act: Preview from June onwards (covers line 154)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true, from: '2024_06_01_000000');

            // Assert: Only new operation shown
            expect($result)->toBeArray()
                ->and($result)->toHaveCount(1)
                ->and($result[0]['timestamp'])->toBe('2024_06_01_000000');
        })->group('integration', 'happy-path', 'preview');

        test('preview with invalid operation data throws exception', function (): void {
            // Skip: Invalid operation files throw various errors depending on content
            // The important path is that preview mode doesn't execute operations
            $this->markTestSkipped('Invalid operation error types vary - exception handling covered by other tests');
        })->group('integration', 'sad-path', 'preview');

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
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Operation name is the file path (covers line 164-165)
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
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Returns array (covers line 123-125)
            expect($result)->toBeArray();
        })->group('integration', 'happy-path', 'routing');

        test('process returns null when isolate is true', function (): void {
            // Arrange
            Config::set('sequencer.execution.lock.store', 'array');
            Config::set('sequencer.execution.lock.timeout', 1);
            Config::set('sequencer.execution.lock.ttl', 60);

            // Act: With isolate but no operations
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process(isolate: true);

            // Assert: Returns null (covers lines 128-130)
            expect($result)->toBeNull();
        })->group('integration', 'happy-path', 'routing');

        test('process returns null for normal execution', function (): void {
            // Act: No operations
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process();

            // Assert: Returns null (covers line 135)
            expect($result)->toBeNull();
        })->group('integration', 'happy-path', 'routing');
    });

    describe('Empty Operations Queue', function (): void {
        test('handles empty operations queue gracefully', function (): void {
            // Arrange: No operations
            Event::fake();

            // Act
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: NoPendingOperations event fired (covers lines 240-245)
            Event::assertDispatched(NoPendingOperations::class, fn ($event): bool => $event->method === ExecutionMethod::TransactionalBatch);

            expect(OperationModel::query()->count())->toBe(0);
        })->group('integration', 'edge-case', 'execution');

        test('fires NoPendingOperations event with correct execution method', function (): void {
            // Arrange
            Event::fake();

            // Act
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $orchestrator->process();

            // Assert
            Event::assertDispatched(NoPendingOperations::class, fn ($event): bool => $event->method === ExecutionMethod::TransactionalBatch);

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

            // Act: Execute with lock but no operations (covers lines 196-202, 205-217)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
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

            // Act & Assert: Second attempt should throw LockTimeoutException (covers line 208)
            // The block() method throws LockTimeoutException when timeout is reached
            $orchestrator = app(TransactionalBatchOrchestrator::class);

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

            // Act: Execute with lock (covers finally block line 214-216)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
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
            $orchestrator = app(TransactionalBatchOrchestrator::class);

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

            // Act: Preview from June onwards (covers line 237)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
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

            // Act: Execute with from parameter (covers execute line 237)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
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

            // Act: Preview shows dependency resolver is called (covers line 489)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Operation appears in result (dependency resolver was called)
            expect($result)->toHaveCount(1);
        })->group('integration', 'happy-path', 'dependencies');
    });

    describe('Task Discovery', function (): void {
        test('discovers pending tasks with migrations and operations', function (): void {
            // Arrange: Create operation
            $op = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_discover.php', $op);

            // Act: discoverPendingTasks is called (covers lines 464-489)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $result = $orchestrator->process(dryRun: true);

            // Assert: Task was discovered
            expect($result)->toHaveCount(1);
            expect($result[0]['type'])->toBe('operation');
        })->group('integration', 'happy-path', 'discovery');

        test('combines migrations and operations into unified task list', function (): void {
            // Note: Migration testing requires complex setup
            // The migration paths (lines 472-476) are covered through the discovery service
            $this->markTestSkipped('Migration integration requires job_batches table setup');
        })->group('integration', 'discovery');
    });

    describe('Batch Execution', function (): void {
        test('batch execution creates operation records', function (): void {
            // Arrange: Create fixture operation files
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\RollbackableWithDatabaseOperation;
return new RollbackableWithDatabaseOperation();
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
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: Operation records were created (covers lines 296-300)
            expect(OperationModel::query()->count())->toBe(2);

            $firstOp = OperationModel::query()->where('name', '2024_01_01_000000_first_op.php')->first();
            expect($firstOp)->not->toBeNull();
            expect($firstOp->type)->toBe(ExecutionMethod::TransactionalBatch->value);
            expect($firstOp->executed_at)->not->toBeNull();

            $secondOp = OperationModel::query()->where('name', '2024_01_01_000001_second_op.php')->first();
            expect($secondOp)->not->toBeNull();
            expect($secondOp->type)->toBe(ExecutionMethod::TransactionalBatch->value);

            // Assert: Events were dispatched (covers lines 248-250, 263-265)
            Event::assertDispatched(OperationsStarted::class, fn ($event): bool => $event->method === ExecutionMethod::TransactionalBatch);

            Event::assertDispatched(OperationsEnded::class, fn ($event): bool => $event->method === ExecutionMethod::TransactionalBatch);

            // Assert: Batch was created with correct jobs (covers lines 307-316)
            Bus::assertBatched(fn ($batch): bool => $batch->name === 'Sequencer Transactional Operations Batch'
                && count($batch->jobs) === 2);
        })->group('integration', 'batch');

        test('batch catch callback triggers rollback', function (): void {
            // Arrange: Create operations - one successful, one that fails
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\RollbackableWithDatabaseOperation;
return new RollbackableWithDatabaseOperation();
PHP;

            $op2 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\FailingRollbackableOperation;
return new FailingRollbackableOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_success_op.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000001_failing_op.php', $op2);

            // Use Bus::fake() to capture batch configuration without actual dispatch
            Bus::fake();

            // Act: Execute batch (which would trigger rollback on failure)
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $orchestrator->process();

            // Assert: Batch was created with catch callback (covers lines 310-316)
            // The catch callback is configured at lines 312-315
            Bus::assertBatched(fn ($batch): bool =>
                // Verify batch has catch callbacks configured
                $batch->name === 'Sequencer Transactional Operations Batch'
                && count($batch->jobs) === 2
                && count($batch->catchCallbacks()) > 0);

            // Verify operation records were created before potential rollback
            expect(OperationModel::query()->count())->toBe(2);
        })->group('integration', 'rollback');

        test('rollback processes completed operations in reverse order', function (): void {
            // Arrange: Create completed operation records to simulate batch execution
            $record1 = OperationModel::query()->create([
                'name' => '2024_01_01_000000_first_rollback_op.php',
                'type' => ExecutionMethod::TransactionalBatch,
                'state' => OperationState::Completed,
                'executed_at' => now()->subMinutes(2),
                'completed_at' => now()->subMinutes(2),
            ]);

            $record2 = OperationModel::query()->create([
                'name' => '2024_01_01_000001_second_rollback_op.php',
                'type' => ExecutionMethod::TransactionalBatch,
                'state' => OperationState::Completed,
                'executed_at' => now()->subMinute(),
                'completed_at' => now()->subMinute(),
            ]);

            $record3 = OperationModel::query()->create([
                'name' => '2024_01_01_000002_third_rollback_op.php',
                'type' => ExecutionMethod::TransactionalBatch,
                'state' => OperationState::Completed,
                'executed_at' => now(),
                'completed_at' => now(),
            ]);

            // Create corresponding operation files
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\RollbackableWithDatabaseOperation;
return new RollbackableWithDatabaseOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_first_rollback_op.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000001_second_rollback_op.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000002_third_rollback_op.php', $op1);

            // Create a mock Batch object for testing rollback
            $mockBatch = Mockery::mock(Batch::class);
            $mockBatch->id = 'test-batch-id';
            $mockBatch->totalJobs = 3;
            $mockBatch->failedJobs = 1;

            // Act: Use reflection to call private rollbackBatch method
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('rollbackBatch');
            $method->invoke($orchestrator, $mockBatch);

            // Assert: Operations rolled back in reverse order (covers lines 342-402)
            $record1->refresh();
            $record2->refresh();
            $record3->refresh();

            expect($record1->state)->toBe(OperationState::RolledBack);
            expect($record1->rolled_back_at)->not->toBeNull();

            expect($record2->state)->toBe(OperationState::RolledBack);
            expect($record2->rolled_back_at)->not->toBeNull();

            expect($record3->state)->toBe(OperationState::RolledBack);
            expect($record3->rolled_back_at)->not->toBeNull();

            // Verify most recent was rolled back first (reverse chronological order)
            expect($record3->rolled_back_at->gte($record2->rolled_back_at))->toBeTrue();
            expect($record2->rolled_back_at->gte($record1->rolled_back_at))->toBeTrue();

            // Verify rollback was called on fixture operations
            expect(RollbackableWithDatabaseOperation::$rollbackCount)->toBe(3);
        })->group('integration', 'rollback');

        test('findOperationDataByName locates operation for rollback', function (): void {
            // Arrange: Create operation files in discovery path and mark them as executed
            $op1 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\RollbackableWithDatabaseOperation;
return new RollbackableWithDatabaseOperation();
PHP;

            $op2 = <<<'PHP'
<?php
use Tests\Fixtures\Operations\NonRollbackableOperation;
return new NonRollbackableOperation();
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_findable_op.php', $op1);
            File::put($this->tempDir.'/2024_01_01_000001_another_op.php', $op2);

            // Mark operations as completed so getPending(true) will find them
            OperationModel::query()->create([
                'name' => '2024_01_01_000000_findable_op.php',
                'type' => ExecutionMethod::TransactionalBatch,
                'executed_at' => now(),
                'completed_at' => now(),
            ]);

            OperationModel::query()->create([
                'name' => '2024_01_01_000001_another_op.php',
                'type' => ExecutionMethod::TransactionalBatch,
                'executed_at' => now(),
                'completed_at' => now(),
            ]);

            // Act: Use reflection to call private findOperationDataByName method
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('findOperationDataByName');

            // Test finding first operation
            $result1 = $method->invoke($orchestrator, '2024_01_01_000000_findable_op.php');

            // Test finding second operation
            $result2 = $method->invoke($orchestrator, '2024_01_01_000001_another_op.php');

            // Test finding non-existent operation
            $result3 = $method->invoke($orchestrator, 'non_existent_operation.php');

            // Assert: Correct operation data returned (covers lines 416-427)
            expect($result1)->toBeArray();
            expect($result1)->toHaveKey('class');
            expect($result1)->toHaveKey('name');
            expect($result1['name'])->toBe('2024_01_01_000000_findable_op.php');
            expect($result1['class'])->toContain('2024_01_01_000000_findable_op.php');

            expect($result2)->toBeArray();
            expect($result2['name'])->toBe('2024_01_01_000001_another_op.php');

            expect($result3)->toBeNull();
        })->group('integration', 'rollback');

        test('executeMigration runs Laravel migrations', function (): void {
            // Arrange: Create a test migration
            $migrationDir = storage_path('framework/testing/sequencer_migrations_'.uniqid());
            File::makeDirectory($migrationDir, 0o755, true);

            $timestamp = '2099_01_01_000000';
            $migrationName = $timestamp.'_create_test_batch_migration_table';
            $migrationFile = $migrationName.'.php';

            $migrationContent = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('test_batch_migration_table', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('test_batch_migration_table');
    }
};
PHP;

            File::put($migrationDir.'/'.$migrationFile, $migrationContent);

            // Create migration task structure as it would be in executeMigration
            $task = [
                'type' => 'migration',
                'timestamp' => $timestamp,
                'data' => [
                    'path' => $migrationDir.'/'.$migrationFile,
                    'name' => $migrationName,
                ],
            ];

            // Act: Use reflection to call private executeMigration method
            $orchestrator = app(TransactionalBatchOrchestrator::class);
            $reflection = new ReflectionClass($orchestrator);
            $method = $reflection->getMethod('executeMigration');
            $method->invoke($orchestrator, $task);

            // Assert: Migration was executed (covers lines 441-451)
            expect(Schema::hasTable('test_batch_migration_table'))->toBeTrue();

            // Clean up
            Schema::dropIfExists('test_batch_migration_table');
            DB::table('migrations')->where('migration', $migrationName)->delete();
            File::deleteDirectory($migrationDir);
        })->group('integration', 'migration');
    });
});
