<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Exceptions\OperationNotRollbackableException;
use Cline\Sequencer\Facades\Sequencer;
use Cline\Sequencer\Orchestrators\BatchOrchestrator;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\Fixtures\Operations\BasicOperation;
use Tests\Fixtures\Operations\ConditionalOperation;
use Tests\Fixtures\Operations\RollbackableOperation;

test('executeIf executes operation when condition is true', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::executeIf(true, $operation, async: false);

    expect(Operation::named('2024_01_01_000001_basic_operation')->completed()->exists())
        ->toBeTrue();
});

test('executeIf does not execute operation when condition is false', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::executeIf(false, $operation, async: false);

    expect(Operation::named('2024_01_01_000001_basic_operation')->exists())
        ->toBeFalse();
});

test('executeUnless executes operation when condition is false', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::executeUnless(false, $operation, async: false);

    expect(Operation::named('2024_01_01_000001_basic_operation')->completed()->exists())
        ->toBeTrue();
});

test('executeUnless does not execute operation when condition is true', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::executeUnless(true, $operation, async: false);

    expect(Operation::named('2024_01_01_000001_basic_operation')->exists())
        ->toBeFalse();
});

test('executeSync executes operation synchronously', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::executeSync($operation);

    expect(Operation::named('2024_01_01_000001_basic_operation')->completed()->exists())
        ->toBeTrue();
});

test('chain returns PendingChain', function (): void {
    Bus::fake();

    $chain = Sequencer::chain([
        __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php',
        __DIR__.'/../Support/TestOperations/2024_01_01_000002_transactional_operation.php',
    ]);

    expect($chain)->toBeInstanceOf(PendingChain::class);
});

test('batch returns PendingBatch', function (): void {
    Bus::fake();

    $batch = Sequencer::batch([
        __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php',
        __DIR__.'/../Support/TestOperations/2024_01_01_000002_transactional_operation.php',
    ]);

    expect($batch)->toBeInstanceOf(PendingBatch::class);
});

test('hasExecuted returns true for completed operations', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::execute($operation, async: false);

    expect(Sequencer::hasExecuted('2024_01_01_000001_basic_operation'))->toBeTrue();
});

test('hasExecuted returns false for non-executed operations', function (): void {
    expect(Sequencer::hasExecuted('non_existent_operation'))->toBeFalse();
});

test('hasFailed returns true for failed operations', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000004_failing_operation.php';

    try {
        Sequencer::execute($operation, async: false);
    } catch (Exception) {
        // Expected to fail
    }

    expect(Sequencer::hasFailed('2024_01_01_000004_failing_operation'))->toBeTrue();
});

test('hasFailed returns false for successful operations', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::execute($operation, async: false);

    expect(Sequencer::hasFailed('2024_01_01_000001_basic_operation'))->toBeFalse();
});

test('getErrors returns errors for failed operations', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000004_failing_operation.php';

    try {
        Sequencer::execute($operation, async: false);
    } catch (Exception) {
        // Expected to fail
    }

    $errors = Sequencer::getErrors('2024_01_01_000004_failing_operation');

    expect($errors)->not->toBeEmpty()
        ->and($errors->first()['exception'])->not->toBeNull();
});

test('using sets custom orchestrator and returns cloned instance', function (): void {
    $original = Sequencer::getFacadeRoot();
    $cloned = Sequencer::using(BatchOrchestrator::class);

    expect($cloned)->not->toBe($original);
});

test('execute without record does not create database entry', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::execute($operation, async: false, record: false);

    expect(Operation::query()->count())->toBe(0);
});

test('rollback executes rollback method on rollbackable operation', function (): void {
    RollbackableOperation::reset();

    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000005_rollbackable_operation.php';

    Sequencer::execute($operation, async: false);

    expect(RollbackableOperation::$executed)->toBeTrue();

    Sequencer::rollback($operation, record: false);

    expect(RollbackableOperation::$rolledBack)->toBeTrue()
        ->and(RollbackableOperation::$executed)->toBeFalse();
});

test('rollback updates database record with rolled_back_at', function (): void {
    RollbackableOperation::reset();

    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000005_rollbackable_operation.php';

    Sequencer::execute($operation, async: false);

    Sequencer::rollback($operation, record: true);

    $record = Operation::named('2024_01_01_000005_rollbackable_operation')->first();

    expect($record)->not->toBeNull()
        ->and($record->rolled_back_at)->not->toBeNull();
});

test('rollback throws when operation not rollbackable', function (): void {
    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::execute($operation, async: false);

    Sequencer::rollback($operation);
})->throws(OperationNotRollbackableException::class);

test('execute skips operation when shouldRun returns false', function (): void {
    ConditionalOperation::reset();
    ConditionalOperation::$shouldRunValue = false;

    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000003_conditional_operation.php';

    Sequencer::execute($operation, async: false);

    expect(ConditionalOperation::$executed)->toBeFalse()
        ->and(Operation::named('2024_01_01_000003_conditional_operation')->exists())->toBeFalse();
});

test('getOrchestrator uses config orchestrator when set', function (): void {
    Config::set('sequencer.orchestrator', BatchOrchestrator::class);

    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::execute($operation, async: false);

    expect(Operation::named('2024_01_01_000001_basic_operation')->completed()->exists())
        ->toBeTrue();
});

test('execute loads operation by fully qualified class name', function (): void {
    BasicOperation::reset();

    Sequencer::execute(BasicOperation::class, async: false);

    expect(BasicOperation::$executed)->toBeTrue();
});

test('recordError respects errors.record config when disabled', function (): void {
    Config::set('sequencer.errors.record', false);

    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000004_failing_operation.php';

    try {
        Sequencer::execute($operation, async: false);
    } catch (Exception) {
        // Expected to fail
    }

    expect(OperationError::query()->count())->toBe(0);
});

test('executeDirect respects auto_transaction config when disabled', function (): void {
    Config::set('sequencer.execution.auto_transaction', false);

    $operation = __DIR__.'/../Support/TestOperations/2024_01_01_000001_basic_operation.php';

    Sequencer::execute($operation, async: false);

    expect(Operation::named('2024_01_01_000001_basic_operation')->completed()->exists())
        ->toBeTrue();
});
