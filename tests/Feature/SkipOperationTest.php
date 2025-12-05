<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\SequentialOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

describe('Skip Operation Feature Tests', function (): void {
    beforeEach(function (): void {
        // Create temp directory for test operations
        $this->tempDir = storage_path('framework/testing/skip_operations_'.uniqid());
        File::makeDirectory($this->tempDir, 0o755, true);
        Config::set('sequencer.execution.discovery_paths', [$this->tempDir]);

        // Set default config values
        Config::set('sequencer.execution.lock.store', 'array');
        Config::set('sequencer.execution.auto_transaction', true);
        Config::set('sequencer.errors.record', true);
        Config::set('sequencer.errors.log_channel', 'stack');

        $this->orchestrator = resolve(SequentialOrchestrator::class);
    });

    afterEach(function (): void {
        // Clean up temp directory
        if (!property_exists($this, 'tempDir') || $this->tempDir === null || !File::isDirectory($this->tempDir)) {
            return;
        }

        File::deleteDirectory($this->tempDir);
    });

    describe('basic skip functionality', function (): void {
        test('operation can be skipped using OperationSkippedException::withReason()', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationSkippedException;

return new class() implements Operation {
    public function handle(): void {
        throw OperationSkippedException::withReason('Custom skip reason');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_with_reason.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::on(fn ($context): bool => $context['reason'] === 'Custom skip reason'));

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = Operation::query()->first();
            expect($operation)->not->toBeNull()
                ->and($operation->name)->toContain('skip_with_reason')
                ->and($operation->skipped_at)->not->toBeNull()
                ->and($operation->skip_reason)->toBe('Custom skip reason')
                ->and($operation->state->value)->toBe('skipped')
                ->and($operation->completed_at)->toBeNull()
                ->and($operation->failed_at)->toBeNull()
                ->and(OperationError::query()->count())->toBe(0);
        })->group('happy-path');

        test('operation skipped with alreadyProcessed reason', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationAlreadyProcessedException;

return new class() implements Operation {
    public function handle(): void {
        // Simulate checking if work was already done
        throw OperationAlreadyProcessedException::create();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_check_and_skip.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = Operation::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('operation skipped with notModified reason', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\ResourceNotModifiedException;

return new class() implements Operation {
    public function handle(): void {
        // Simulate API returning 304 Not Modified
        throw ResourceNotModifiedException::create();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_api_not_modified.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = Operation::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('operation skipped with recordExists reason', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\RecordAlreadyExistsException;

return new class() implements Operation {
    public function handle(): void {
        throw RecordAlreadyExistsException::create();
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_record_exists.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = Operation::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('operation skipped with custom condition not met', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\ConditionNotMetException;

return new class() implements Operation {
    public function handle(): void {
        throw ConditionNotMetException::forCondition('feature.new_dashboard is disabled');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_feature_disabled.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = Operation::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('happy-path');
    });

    describe('skip vs ConditionalExecution comparison', function (): void {
        test('ConditionalExecution prevents execution while OperationSkippedException handles runtime skips', function (): void {
            // Arrange - ConditionalExecution operation
            $conditionalContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\ConditionalExecution;

return new class() implements ConditionalExecution {
    public function shouldRun(): bool {
        return false; // Skip before execution
    }

    public function handle(): void {
        throw SimulatedFailureException::shouldNeverExecute();
    }
};
PHP;

            // Arrange - OperationSkippedException operation
            $skipContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationSkippedException;

return new class() implements Operation {
    public function handle(): void {
        // Execution starts, then decides to skip
        throw OperationSkippedException::withReason('Runtime skip decision');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_conditional.php', $conditionalContent);
            File::put($this->tempDir.'/2024_01_02_000000_skip_exception.php', $skipContent);

            Log::shouldReceive('channel')->twice()->andReturnSelf();
            Log::shouldReceive('info')->twice();

            // Act
            $this->orchestrator->process();

            // Assert
            $operations = Operation::query()->orderBy('name')->get();
            expect($operations)->toHaveCount(2);

            // Both should have completed_at or skipped_at set
            expect($operations[0]->completed_at ?? $operations[0]->skipped_at)->not->toBeNull();
            expect($operations[1]->skipped_at)->not->toBeNull();
        })->group('edge-case');
    });

    describe('transaction handling with skip', function (): void {
        test('skip inside transaction does not cause rollback', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\WithinTransaction;
use Cline\Sequencer\Exceptions\OperationSkippedException;

return new class() implements Operation, WithinTransaction {
    public function handle(): void {
        // Simulate work inside transaction then skip
        throw OperationSkippedException::withReason('Decided to skip inside transaction');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_skip_in_transaction.php', $operationContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = Operation::query()->first();
            expect($operation->skipped_at)->not->toBeNull()
                ->and($operation->failed_at)->toBeNull();
        })->group('edge-case');
    });

    describe('multiple operations with mixed results', function (): void {
        test('can process sequence with success, skip, and failure', function (): void {
            // Arrange
            $successContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;

return new class() implements Operation {
    public function handle(): void {
        // Success
    }
};
PHP;

            $skipContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationSkippedException;

return new class() implements Operation {
    public function handle(): void {
        throw OperationSkippedException::withReason('Skipped operation');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_success.php', $successContent);
            File::put($this->tempDir.'/2024_01_02_000000_skip.php', $skipContent);

            Log::shouldReceive('channel')->once()->andReturnSelf();
            Log::shouldReceive('info')->once();

            // Act
            $this->orchestrator->process();

            // Assert
            $operations = Operation::query()->oldest('executed_at')->get();
            expect($operations)->toHaveCount(2);
            expect($operations[0]->completed_at)->not->toBeNull();
            expect($operations[1]->skipped_at)->not->toBeNull();
        })->group('happy-path');
    });

    describe('error handling', function (): void {
        test('skipped operations do not create error records', function (): void {
            // Arrange
            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationSkippedException;

return new class() implements Operation {
    public function handle(): void {
        throw OperationSkippedException::withReason('No error should be recorded');
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

        test('custom log channel receives skip notifications', function (): void {
            // Arrange
            Config::set('sequencer.errors.log_channel', 'custom');

            $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationSkippedException;

return new class() implements Operation {
    public function handle(): void {
        throw OperationSkippedException::withReason('Custom channel test');
    }
};
PHP;

            File::put($this->tempDir.'/2024_01_01_000000_custom_log.php', $operationContent);

            Log::shouldReceive('channel')->once()->with('custom')->andReturnSelf();
            Log::shouldReceive('info')->once()->with('Operation skipped during execution', Mockery::any());

            // Act
            $this->orchestrator->process();

            // Assert
            $operation = Operation::query()->first();
            expect($operation->skipped_at)->not->toBeNull();
        })->group('edge-case');
    });
});
