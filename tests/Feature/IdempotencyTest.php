<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Idempotent;
use Cline\Sequencer\Contracts\Operation;
use Tests\Fixtures\Operations\IdempotentOperation;

/**
 * Idempotency Test Suite
 *
 * Tests idempotent operation behavior.
 */
describe('Idempotency', function (): void {
    beforeEach(function (): void {
        IdempotentOperation::reset();
    });

    describe('Idempotent Interface', function (): void {
        test('operation implements idempotent interface', function (): void {
            $operation = new IdempotentOperation();

            expect($operation)->toBeInstanceOf(Idempotent::class);
        });

        test('operation implements operation interface', function (): void {
            $operation = new IdempotentOperation();

            expect($operation)->toBeInstanceOf(Operation::class);
        });
    });

    describe('Multiple Executions', function (): void {
        test('operation can be executed multiple times', function (): void {
            $operation = new IdempotentOperation();

            $operation->handle();

            expect(IdempotentOperation::$executionCount)->toBe(1);

            $operation->handle();
            expect(IdempotentOperation::$executionCount)->toBe(2);

            $operation->handle();
            expect(IdempotentOperation::$executionCount)->toBe(3);
        });

        test('execution count increments correctly', function (): void {
            $operation = new IdempotentOperation();

            foreach (range(1, 5) as $count) {
                $operation->handle();
                expect(IdempotentOperation::$executionCount)->toBe($count);
            }
        });
    });

    describe('Safety Guarantees', function (): void {
        test('multiple executions do not cause errors', function (): void {
            $operation = new IdempotentOperation();

            expect(fn () => $operation->handle())->not->toThrow(Exception::class);
            expect(fn () => $operation->handle())->not->toThrow(Exception::class);
            expect(fn () => $operation->handle())->not->toThrow(Exception::class);
        });
    });
});
