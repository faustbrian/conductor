<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Exceptions\CannotAcquireLockException;
use Cline\Sequencer\Exceptions\OperationFailedException;
use Cline\Sequencer\SequentialOrchestrator;
use Cline\Sequencer\Support\DependencyResolver;
use Cline\Sequencer\Support\MigrationDiscovery;
use Cline\Sequencer\Support\OperationDiscovery;
use Cline\Sequencer\Testing\OperationFake;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

describe('SequentialOrchestrator Unit Tests', function (): void {
    beforeEach(function (): void {
        // Reset OperationFake state
        OperationFake::tearDown();

        // Create temp directory for test operations
        $this->tempDir = storage_path('framework/testing/operations_'.uniqid());
        File::makeDirectory($this->tempDir, 0o755, true);
        Config::set('sequencer.execution.discovery_paths', [$this->tempDir]);

        // Set default config values
        Config::set('sequencer.execution.lock.store', 'array');
        Config::set('sequencer.execution.lock.timeout', 1); // Short timeout for tests
        Config::set('sequencer.execution.lock.ttl', 10);
        Config::set('sequencer.execution.auto_transaction', true);
        Config::set('sequencer.errors.record', true);
        Config::set('sequencer.errors.log_channel', 'stack');
        Config::set('sequencer.queue.connection', 'sync');
        Config::set('sequencer.queue.queue', 'default');

        $this->orchestrator = app(SequentialOrchestrator::class);
    });

    afterEach(function (): void {
        // Clean up temp directory
        if (property_exists($this, 'tempDir') && $this->tempDir !== null && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        OperationFake::tearDown();
    });

    describe('dry-run preview', function (): void {
        test('returns preview array when dry-run is enabled', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {}
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_test_operation.php', $operationContent);

            // Act
            $result = $this->orchestrator->process(dryRun: true);

            // Assert
            expect($result)->toBeArray()
                ->and($result)->toHaveCount(1)
                ->and($result[0]['type'])->toBe('operation')
                ->and($result[0]['timestamp'])->toBe('2024_01_01_000000');
            expect(OperationModel::query()->count())->toBe(0); // Nothing executed
        })->group('happy-path');

        test('filters preview results by from timestamp', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
return new class() implements Operation { public function handle(): void {} };
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_op1.php', $operationContent);
            File::put($this->tempDir.'/2024_01_02_000000_op2.php', $operationContent);
            File::put($this->tempDir.'/2024_01_03_000000_op3.php', $operationContent);

            // Act
            $result = $this->orchestrator->process(dryRun: true, from: '2024_01_02_000000');

            // Assert
            expect($result)->toHaveCount(2)
                ->and($result[0]['timestamp'])->toBe('2024_01_02_000000')
                ->and($result[1]['timestamp'])->toBe('2024_01_03_000000');
        })->group('edge-case');
    });

    describe('lock-based isolation', function (): void {
        test('processes with lock when isolate is enabled', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
return new class() implements Operation { public function handle(): void {} };
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_test_operation.php', $operationContent);

            // Act
            $result = $this->orchestrator->process(isolate: true);

            // Assert
            expect($result)->toBeNull();
            expect(OperationModel::query()->count())->toBe(1);
        })->group('happy-path');

        test('throws CannotAcquireLockException when lock cannot be acquired', function (): void {
            // Arrange
            $lock = Mockery::mock(Lock::class);
            $lock->shouldReceive('block')->with(1)->andReturn(false);

            Cache::shouldReceive('store')->with('array')->andReturnSelf();
            Cache::shouldReceive('lock')->with('sequencer:process', 10)->andReturn($lock);

            // Act & Assert
            expect(fn () => $this->orchestrator->process(isolate: true))
                ->toThrow(CannotAcquireLockException::class, 'Could not acquire sequencer lock within timeout period');
        })->group('sad-path');

        test('releases lock even when execution fails', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
return new class() implements Operation {
    public function handle(): void {
        throw TestExecutionException::shouldNotExecute();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_failing_operation.php', $operationContent);

            // Act & Assert
            expect(fn () => $this->orchestrator->process(isolate: true))
                ->toThrow(Exception::class);

            // Verify lock was released by acquiring it
            $lock = Cache::lock('sequencer:process', 10);
            expect($lock->block(1))->toBeTrue();
            $lock->release();
        })->group('sad-path');
    });

    describe('operation execution with from timestamp', function (): void {
        test('filters operations by from timestamp during execution', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
return new class() implements Operation { public function handle(): void {} };
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_op1.php', $operationContent);
            File::put($this->tempDir.'/2024_01_02_000000_op2.php', $operationContent);
            File::put($this->tempDir.'/2024_01_03_000000_op3.php', $operationContent);

            // Act
            $this->orchestrator->process(from: '2024_01_02_000000');

            // Assert
            expect(OperationModel::query()->count())->toBe(2);
            expect(OperationModel::query()->where('name', '2024_01_01_000000_op1.php')->exists())->toBeFalse();
        })->group('edge-case');
    });

    describe('ConditionalExecution operations', function (): void {
        test('skips operation when ConditionalExecution returns false', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class() implements Operation, ConditionalExecution {
    public function handle(): void {
        throw TestExecutionException::shouldNotExecute();
    }

    public function shouldRun(): bool {
        return false;
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_conditional_operation.php', $operationContent);

            Log::shouldReceive('channel')->with('stack')->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped by shouldRun() condition', Mockery::any());

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation)->not->toBeNull()
                ->and($operation->executed_at)->not->toBeNull()
                ->and($operation->completed_at)->not->toBeNull();
        })->group('edge-case');

        test('executes operation when ConditionalExecution returns true', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class() implements Operation, ConditionalExecution {
    public function handle(): void {
        // Executed normally
    }

    public function shouldRun(): bool {
        return true;
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_conditional_operation.php', $operationContent);

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation)->not->toBeNull()
                ->and($operation->completed_at)->not->toBeNull();
        })->group('happy-path');
    });

    describe('OperationFake integration', function (): void {
        test('skips execution when OperationFake is enabled', function (): void {
            // Arrange
            OperationFake::setup();

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {
        throw TestExecutionException::shouldNotExecuteWhenFaking();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_test_operation.php', $operationContent);

            // Act
            $this->orchestrator->process();

            // Assert
            expect(OperationFake::executed())->toHaveCount(1);
            expect(OperationModel::query()->count())->toBe(0); // No real database records when faking
        })->group('happy-path');
    });

    describe('async operation dispatching', function (): void {
        test('records async operation type correctly', function (): void {
            // Arrange
            // Use Queue fake to avoid serialization issues with anonymous classes
            Queue::fake();

            $orchestrator = new SequentialOrchestrator(
                app(OperationDiscovery::class),
                app(MigrationDiscovery::class),
                app(DependencyResolver::class),
            );

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Contracts\Asynchronous;

return new class() implements Operation, Asynchronous {
    public function handle(): void {
        // Async operation
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_async_operation.php', $operationContent);

            // Act
            $orchestrator->process();

            // Assert - Verify operation was created with async type but not dispatched
            $operation = OperationModel::query()->first();
            expect($operation)->not->toBeNull()
                ->and($operation->type)->toBe('async')
                ->and($operation->executed_at)->not->toBeNull()
                ->and($operation->completed_at)->toBeNull(); // Async operations without queue aren't completed
        })->group('happy-path');
    });

    describe('transaction handling', function (): void {
        test('wraps operation in transaction when WithinTransaction interface implemented', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', false);

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Contracts\WithinTransaction;

return new class() implements Operation, WithinTransaction {
    public function handle(): void {
        // Operation with transaction
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_transactional_operation.php', $operationContent);

            DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());

            // Act
            $this->orchestrator->process();

            // Assert - verified by DB::transaction mock
        })->group('happy-path');

        test('wraps operation in transaction when auto_transaction is enabled', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', true);

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {
        // Operation with auto transaction
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_test_operation.php', $operationContent);

            DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());

            // Act
            $this->orchestrator->process();

            // Assert - verified by DB::transaction mock
        })->group('happy-path');

        test('executes operation without transaction when WithinTransaction not implemented and auto_transaction disabled', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', false);

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {
        // Operation without transaction
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_test_operation.php', $operationContent);

            DB::shouldReceive('transaction')->never();

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation)->not->toBeNull()
                ->and($operation->completed_at)->not->toBeNull();
        })->group('edge-case');
    });

    describe('rollback functionality', function (): void {
        test('rolls back executed operations when later operation fails', function (): void {
            // Arrange
            $operation1Content = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Contracts\Rollbackable;

return new class() implements Operation, Rollbackable {
    public function handle(): void {
        // First operation succeeds
    }

    public function rollback(): void {
        // Rollback logic
    }
};
PHP;

            $operation2Content = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {
        throw TestExecutionException::secondOperationFails();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_op1.php', $operation1Content);
            File::put($this->tempDir.'/2024_01_01_000001_op2.php', $operation2Content);

            Log::shouldReceive('channel')->with('stack')->andReturnSelf();
            Log::shouldReceive('info')->with('Operation rolled back successfully', Mockery::any());
            Log::shouldReceive('error')->with('Operation failed', Mockery::any());

            // Act & Assert
            expect(fn () => $this->orchestrator->process())
                ->toThrow(Exception::class, 'Second operation fails');

            // Verify rollback was recorded
            $operation1 = OperationModel::query()->where('name', '2024_01_01_000000_op1.php')->first();
            expect($operation1->rolled_back_at)->not->toBeNull();
        })->group('sad-path');

        test('logs error when rollback fails', function (): void {
            // Arrange
            $operation1Content = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Contracts\Rollbackable;

return new class() implements Operation, Rollbackable {
    public function handle(): void {
        // First operation succeeds
    }

    public function rollback(): void {
        throw TestExecutionException::rollbackFailed();
    }
};
PHP;

            $operation2Content = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {
        throw TestExecutionException::secondOperationFails();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_op1.php', $operation1Content);
            File::put($this->tempDir.'/2024_01_01_000001_op2.php', $operation2Content);

            Log::shouldReceive('channel')->with('stack')->andReturnSelf();
            Log::shouldReceive('error')->with('Failed to rollback operation', Mockery::any());
            Log::shouldReceive('error')->with('Operation failed', Mockery::any());

            // Act & Assert
            expect(fn () => $this->orchestrator->process())
                ->toThrow(Exception::class);
        })->group('sad-path');

        test('skips rollback for operations not implementing Rollbackable', function (): void {
            // Arrange
            $operation1Content = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {
        // First operation succeeds (no rollback)
    }
};
PHP;

            $operation2Content = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;

return new class() implements Operation {
    public function handle(): void {
        throw TestExecutionException::secondOperationFails();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_op1.php', $operation1Content);
            File::put($this->tempDir.'/2024_01_01_000001_op2.php', $operation2Content);

            Log::shouldReceive('channel')->with('stack')->andReturnSelf();
            Log::shouldReceive('error')->with('Operation failed', Mockery::any());

            // Act & Assert
            expect(fn () => $this->orchestrator->process())
                ->toThrow(Exception::class);

            // Verify no rollback was performed
            $operation1 = OperationModel::query()->where('name', '2024_01_01_000000_op1.php')->first();
            expect($operation1->rolled_back_at)->toBeNull();
        })->group('edge-case');
    });

    describe('error recording', function (): void {
        test('records error when operation fails', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\OperationFailedException;

return new class() implements Operation {
    public function handle(): void {
        throw OperationFailedException::withMessage('Test error message');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_failing_operation.php', $operationContent);

            Log::shouldReceive('channel')->with('stack')->andReturnSelf();
            Log::shouldReceive('error')->with('Operation failed', Mockery::any());

            // Act & Assert
            expect(fn () => $this->orchestrator->process())
                ->toThrow(RuntimeException::class, 'Test error message');

            // Verify error was recorded
            $operation = OperationModel::query()->first();
            expect($operation)->not->toBeNull()
                ->and($operation->failed_at)->not->toBeNull();

            $error = OperationError::query()->where('operation_id', $operation->id)->first();
            expect($error)->not->toBeNull()
                ->and($error->exception)->toBe(OperationFailedException::class)
                ->and($error->message)->toBe('Test error message')
                ->and($error->trace)->not->toBeNull()
                ->and($error->context)->toBeArray();
        })->group('sad-path');

        test('skips error recording when disabled in config', function (): void {
            // Arrange
            Config::set('sequencer.errors.record', false);

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\OperationFailedException;

return new class() implements Operation {
    public function handle(): void {
        throw OperationFailedException::withMessage('Test error');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_failing_operation.php', $operationContent);

            // Act & Assert
            expect(fn () => $this->orchestrator->process())
                ->toThrow(RuntimeException::class);

            // Verify no error was recorded
            expect(OperationError::query()->count())->toBe(0);
        })->group('edge-case');

        test('logs error to configured channel', function (): void {
            // Arrange
            Config::set('sequencer.errors.log_channel', 'custom');

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\OperationFailedException;

return new class() implements Operation {
    public function handle(): void {
        throw OperationFailedException::withMessage('Test error');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_failing_operation.php', $operationContent);

            Log::shouldReceive('channel')->once()->with('custom')->andReturnSelf();
            Log::shouldReceive('error')->with('Operation failed', Mockery::any());

            // Act & Assert
            expect(fn () => $this->orchestrator->process())
                ->toThrow(RuntimeException::class);
        })->group('edge-case');
    });

    describe('skip operation exception handling', function (): void {
        test('marks operation as skipped when SkipOperationException is thrown', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation {
    public function handle(): void {
        throw SkipOperationException::create('Test skip reason');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_operation.php', $operationContent);

            Log::shouldReceive('channel')->once()->with('stack')->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::on(fn ($context): bool => $context['reason'] === 'Test skip reason'));

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation)->not->toBeNull()
                ->and($operation->skipped_at)->not->toBeNull()
                ->and($operation->completed_at)->toBeNull()
                ->and($operation->failed_at)->toBeNull();
        })->group('happy-path');

        test('uses static constructor alreadyProcessed', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation {
    public function handle(): void {
        throw SkipOperationException::alreadyProcessed();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_already_processed.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::on(fn ($context): bool => $context['reason'] === 'Operation already processed'));

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('uses static constructor notModified', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation {
    public function handle(): void {
        throw SkipOperationException::notModified();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_not_modified.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::on(fn ($context): bool => $context['reason'] === 'Resource not modified'));

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('uses static constructor recordExists', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation {
    public function handle(): void {
        throw SkipOperationException::recordExists();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_record_exists.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::on(fn ($context): bool => $context['reason'] === 'Record already exists'));

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('uses static constructor conditionNotMet', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation {
    public function handle(): void {
        throw SkipOperationException::conditionNotMet('feature flag disabled');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_condition.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::on(fn ($context): bool => $context['reason'] === 'Condition not met: feature flag disabled'));

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('skipped operations do not create error records', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation {
    public function handle(): void {
        throw SkipOperationException::create('Skip without error');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_no_error.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            expect(OperationError::query()->count())->toBe(0);
        })->group('happy-path');

        test('logs skip to custom channel when configured', function (): void {
            // Arrange
            Config::set('sequencer.errors.log_channel', 'custom');

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation {
    public function handle(): void {
        throw SkipOperationException::create('Custom channel skip');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_custom_channel_skip.php', $operationContent);

            Log::shouldReceive('channel')->once()->with('custom')->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::any());

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('edge-case');

        test('skip inside transaction does not rollback', function (): void {
            // Arrange
            Config::set('sequencer.execution.auto_transaction', true);

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Exceptions\SkipOperationException;

return new class() implements Operation, WithinTransaction {
    public function handle(): void {
        throw SkipOperationException::create('Skip inside transaction');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_in_transaction.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = OperationModel::query()->first();
            expect($operation->skipped_at)->not->toBeNull()
                ->and($operation->failed_at)->toBeNull();
        })->group('edge-case');
    });
});
