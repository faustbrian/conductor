<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Rollbackable;
use Tests\Fixtures\Operations\RollbackableOperation;

/**
 * Rollback Support Test Suite
 *
 * Tests rollback functionality for failed operations.
 */
describe('Rollback Support', function (): void {
    beforeEach(function (): void {
        RollbackableOperation::reset();
    });

    describe('Rollbackable Interface', function (): void {
        test('operation implements rollback method', function (): void {
            $operation = new RollbackableOperation();

            expect($operation)->toBeInstanceOf(Rollbackable::class);
        });

        test('rollback method reverses operation', function (): void {
            $operation = new RollbackableOperation();
            $operation->handle();

            expect(RollbackableOperation::$executed)->toBeTrue();

            $operation->rollback();

            expect(RollbackableOperation::$rolledBack)->toBeTrue();
            expect(RollbackableOperation::$executed)->toBeFalse();
        });

        test('rollback sets rolled back flag', function (): void {
            $operation = new RollbackableOperation();
            $operation->rollback();

            expect(RollbackableOperation::$rolledBack)->toBeTrue();
        });
    });

    describe('Rollback Scenarios', function (): void {
        test('operation can be rolled back after execution', function (): void {
            $operation = new RollbackableOperation();
            $operation->handle();
            $operation->rollback();

            expect(RollbackableOperation::$rolledBack)->toBeTrue();
        });

        test('rollback without execution still works', function (): void {
            $operation = new RollbackableOperation();
            $operation->rollback();

            expect(RollbackableOperation::$executed)->toBeFalse();
            expect(RollbackableOperation::$rolledBack)->toBeTrue();
        });
    });
});
