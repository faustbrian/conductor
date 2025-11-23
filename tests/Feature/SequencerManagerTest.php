<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Facades\Sequencer;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Support\Facades\Bus;

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
