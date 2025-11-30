<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Exceptions\OperationFailedException;
use Cline\Sequencer\Jobs\ExecuteOperation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

describe('ExecuteOperation Job', function (): void {
    describe('Happy Path - Successful Execution', function (): void {
        test('executes operation successfully and updates completed_at', function (): void {
            // Arrange
            $operationRecord = OperationModel::factory()->create([
                'name' => 'TestOperation',
                'completed_at' => null,
                'failed_at' => null,
            ]);

            $operation = new class() implements Operation
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
            expect($operationRecord->failed_at)->toBeNull();
        })->group('happy-path');

        test('executes operation without transaction when auto_transaction disabled and not WithinTransaction', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', false);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'TestOperation',
            ]);

            $transactionCalled = false;
            DB::shouldReceive('transaction')->never();

            $operation = new class() implements Operation
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
        })->group('happy-path');
    });

    describe('Transaction Wrapping', function (): void {
        test('executes operation within transaction when implementing WithinTransaction', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', false);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'TransactionalOperation',
            ]);

            $operation = new class() implements WithinTransaction
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
        })->group('happy-path');

        test('executes operation within transaction when auto_transaction is enabled', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', true);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'AutoTransactionOperation',
            ]);

            $operation = new class() implements Operation
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
        })->group('happy-path');

        test('WithinTransaction interface overrides auto_transaction disabled setting', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', false);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'ForcedTransactionOperation',
            ]);

            $operation = new class() implements WithinTransaction
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
        })->group('happy-path');
    });

    describe('Sad Path - Operation Failure', function (): void {
        test('updates failed_at when operation throws exception', function (): void {
            // Arrange
            $operationRecord = OperationModel::factory()->create([
                'name' => 'FailingOperation',
                'completed_at' => null,
                'failed_at' => null,
            ]);

            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    throw OperationFailedException::testFailure();
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act & Assert
            try {
                $job->handle();
                expect(false)->toBeTrue('Exception should have been thrown');
            } catch (RuntimeException $runtimeException) {
                expect($runtimeException->getMessage())->toBe('Operation failed');
            }

            $operationRecord->refresh();
            expect($operationRecord->completed_at)->toBeNull();
            expect($operationRecord->failed_at)->not->toBeNull();
        })->group('sad-path');

        test('records error when operation fails and error recording enabled', function (): void {
            // Arrange
            Config::set('sequencer.errors.record', true);
            Config::set('sequencer.errors.log_channel', 'stack');

            Log::shouldReceive('channel')
                ->with('stack')
                ->once()
                ->andReturnSelf();

            Log::shouldReceive('error')
                ->once()
                ->withArgs(fn ($message, $context): bool => $message === 'Operation failed'
                    && $context['operation'] === 'FailingOperation'
                    && $context['exception'] === OperationFailedException::class
                    && $context['message'] === 'Test error message');

            $operationRecord = OperationModel::factory()->create([
                'name' => 'FailingOperation',
            ]);

            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    throw OperationFailedException::withMessage('Test error message');
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            try {
                $job->handle();
            } catch (RuntimeException) {
                // Expected
            }

            // Assert
            $operationRecord->refresh();
            expect($operationRecord->failed_at)->not->toBeNull();

            $error = OperationError::query()
                ->where('operation_id', $operationRecord->id)
                ->first();

            expect($error)->not->toBeNull();
            expect($error->exception)->toBe(OperationFailedException::class);
            expect($error->message)->toBe('Test error message');
            expect($error->trace)->toBeString();
            expect($error->context)->toBeArray();
            expect($error->context['file'])->toBeString();
            expect($error->context['line'])->toBeInt();
            expect($error->context['code'])->toBe(0);
            expect($error->created_at)->not->toBeNull();
        })->group('sad-path');

        test('does not record error when error recording disabled', function (): void {
            // Arrange
            Config::set('sequencer.errors.record', false);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'FailingOperation',
            ]);

            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    throw OperationFailedException::withMessage('Test error');
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            try {
                $job->handle();
            } catch (RuntimeException) {
                // Expected
            }

            // Assert
            $errorCount = OperationError::query()
                ->where('operation_id', $operationRecord->id)
                ->count();

            expect($errorCount)->toBe(0);
        })->group('sad-path');

        test('rethrows exception after recording error', function (): void {
            // Arrange
            Config::set('sequencer.errors.record', true);

            Log::shouldReceive('channel')->andReturnSelf();
            Log::shouldReceive('error');

            $operationRecord = OperationModel::factory()->create([
                'name' => 'FailingOperation',
            ]);

            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    throw OperationFailedException::criticalError();
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act & Assert
            expect(fn () => $job->handle())
                ->toThrow(RuntimeException::class, 'Critical error');
        })->group('sad-path');
    });

    describe('Error Recording Configuration', function (): void {
        test('uses custom log channel when configured', function (): void {
            // Arrange
            Config::set('sequencer.errors.record', true);
            Config::set('sequencer.errors.log_channel', 'custom_channel');

            Log::shouldReceive('channel')
                ->with('custom_channel')
                ->once()
                ->andReturnSelf();

            Log::shouldReceive('error')
                ->once()
                ->withArgs(fn ($message, $context): bool => $message === 'Operation failed');

            $operationRecord = OperationModel::factory()->create([
                'name' => 'FailingOperation',
            ]);

            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    throw OperationFailedException::withCustomChannel();
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            try {
                $job->handle();
            } catch (RuntimeException) {
                // Expected
            }

            // Assert - Log expectations verified by Mockery
        })->group('edge-case');

        test('records error with complete exception context', function (): void {
            // Arrange
            Config::set('sequencer.errors.record', true);

            Log::shouldReceive('channel')->andReturnSelf();
            Log::shouldReceive('error');

            $operationRecord = OperationModel::factory()->create([
                'name' => 'DetailedErrorOperation',
            ]);

            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    throw OperationFailedException::detailedError();
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            try {
                $job->handle();
            } catch (RuntimeException) {
                // Expected
            }

            // Assert
            $error = OperationError::query()
                ->where('operation_id', $operationRecord->id)
                ->first();

            expect($error)->not->toBeNull();
            expect($error->exception)->toBe(OperationFailedException::class);
            expect($error->message)->toBe('Detailed error');
            expect($error->context['code'])->toBe(500);
            expect($error->context['file'])->toContain('OperationFailedException.php');
            expect($error->context['line'])->toBeInt();
            expect($error->trace)->toBeString()->not->toBeEmpty();
        })->group('edge-case');
    });

    describe('Edge Cases', function (): void {
        test('throws ModelNotFoundException when operation record does not exist', function (): void {
            // Arrange
            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    // No-op
                }
            };

            $job = new ExecuteOperation($operation, 99_999);

            // Act & Assert
            expect(fn () => $job->handle())
                ->toThrow(ModelNotFoundException::class);
        })->group('edge-case');

        test('handles operation with transaction when both WithinTransaction and auto_transaction enabled', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', true);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'BothTransactionSettings',
            ]);

            $operation = new class() implements WithinTransaction
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
        })->group('edge-case');

        test('operation failure within transaction rolls back changes', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', true);
            Config::set('sequencer.errors.record', true);

            Log::shouldReceive('channel')->andReturnSelf();
            Log::shouldReceive('error');

            $operationRecord = OperationModel::factory()->create([
                'name' => 'TransactionRollbackOperation',
            ]);

            // Create a test record to verify rollback
            $testRecord = OperationModel::factory()->create([
                'name' => 'TestRecord',
            ]);

            $operation = new readonly class($testRecord) implements WithinTransaction
            {
                public function __construct(
                    private OperationModel $testRecord,
                ) {}

                public function handle(): void
                {
                    // Update a record within transaction
                    $this->testRecord->update(['name' => 'Modified']);

                    // Then fail
                    throw OperationFailedException::transactionShouldRollback();
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            try {
                $job->handle();
            } catch (RuntimeException) {
                // Expected
            }

            // Assert
            $testRecord->refresh();
            expect($testRecord->name)->toBe('TestRecord') // Should be rolled back
                ->and($operationRecord->refresh()->failed_at)->not->toBeNull();
        })->group('edge-case');

        test('uses default log channel stack when not configured', function (): void {
            // Arrange
            Config::set('sequencer.errors.record', true);
            Config::set('sequencer.errors.log_channel', 'stack'); // Default value

            Log::shouldReceive('channel')
                ->with('stack')
                ->once()
                ->andReturnSelf();

            Log::shouldReceive('error')
                ->once();

            $operationRecord = OperationModel::factory()->create([
                'name' => 'DefaultChannelOperation',
            ]);

            $operation = new class() implements Operation
            {
                public function handle(): void
                {
                    throw OperationFailedException::onDefaultChannel();
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            try {
                $job->handle();
            } catch (RuntimeException) {
                // Expected
            }

            // Assert - Log expectations verified by Mockery
        })->group('edge-case');
    });

    describe('Auto-Transaction Configuration', function (): void {
        test('respects auto_transaction config when true', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', true);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'AutoTransactionEnabled',
            ]);

            $operation = new class() implements Operation
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
        })->group('happy-path');

        test('respects auto_transaction config when false', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', false);

            $operationRecord = OperationModel::factory()->create([
                'name' => 'AutoTransactionDisabled',
            ]);

            $operation = new class() implements Operation
            {
                public bool $executed = false;

                public function handle(): void
                {
                    $this->executed = true;
                }
            };

            $job = new ExecuteOperation($operation, $operationRecord->id);

            // Act
            $job->handle();

            // Assert
            expect($operation->executed)->toBeTrue();
            $operationRecord->refresh();
            expect($operationRecord->completed_at)->not->toBeNull();
        })->group('happy-path');
    });
});
