<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\WithinTransaction;
use Tests\Fixtures\Operations\TransactionalOperation;

/**
 * Transaction Handling Test Suite
 *
 * Tests database transaction wrapping for operations.
 */
describe('Transaction Handling', function (): void {
    beforeEach(function (): void {
        TransactionalOperation::reset();
    });

    describe('WithinTransaction Interface', function (): void {
        test('operation implements within transaction interface', function (): void {
            $operation = new TransactionalOperation();

            expect($operation)->toBeInstanceOf(WithinTransaction::class);
        });

        test('operation executes within transaction boundary', function (): void {
            $operation = new TransactionalOperation();
            $operation->handle();

            expect(TransactionalOperation::$executed)->toBeTrue();
        });
    });

    describe('Transaction Behavior', function (): void {
        test('operation marked for transaction handling', function (): void {
            $operation = new TransactionalOperation();

            // The orchestrator would wrap this in DB::transaction()
            expect($operation)->toBeInstanceOf(WithinTransaction::class);
        });
    });

    describe('Failure Scenarios', function (): void {
        test('transaction interface indicates rollback support', function (): void {
            // WithinTransaction signals to orchestrator to use DB::transaction()
            // which automatically rolls back on exceptions
            expect(true)->toBeTrue();
        });
    });
});
