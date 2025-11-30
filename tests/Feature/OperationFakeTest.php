<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Testing\OperationFake;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Operations\BasicOperation;
use Tests\Fixtures\Operations\ConditionalOperation;
use Tests\Fixtures\Operations\IdempotentOperation;

/**
 * Operation Fake Test Suite
 *
 * Tests operation faking capabilities for testing scenarios.
 */
describe('Operation Fake', function (): void {
    beforeEach(function (): void {
        OperationFake::tearDown(); // Clear any previous state
        OperationFake::setup();
        BasicOperation::reset();
        ConditionalOperation::reset();
        IdempotentOperation::reset();
    });

    afterEach(function (): void {
        OperationFake::tearDown();
    });

    describe('Setup and Teardown', function (): void {
        test('enables faking when setup is called', function (): void {
            expect(OperationFake::isFaking())->toBeTrue();
        });

        test('disables faking when teardown is called', function (): void {
            OperationFake::tearDown();

            expect(OperationFake::isFaking())->toBeFalse();
        });

        test('clears executed operations on teardown', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            expect(OperationFake::executed())->toHaveCount(1);

            OperationFake::tearDown();

            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('clears executed operations on setup', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            expect(OperationFake::executed())->toHaveCount(1);

            OperationFake::setup();

            expect(OperationFake::executed())->toHaveCount(0);
        });
    });

    describe('Recording Operations', function (): void {
        test('records operation when faking is enabled', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            expect(OperationFake::executed())->toHaveCount(1);
            expect(OperationFake::executed()[0]['class'])->toBe(BasicOperation::class);
            expect(OperationFake::executed()[0]['operation'])->toBe($operation);
        });

        test('does not record operation when faking is disabled', function (): void {
            OperationFake::tearDown();

            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('records multiple operations', function (): void {
            $operation1 = new BasicOperation();
            $operation2 = new ConditionalOperation();

            OperationFake::record(BasicOperation::class, $operation1);
            OperationFake::record(ConditionalOperation::class, $operation2);

            expect(OperationFake::executed())->toHaveCount(2);
        });

        test('records same operation class multiple times', function (): void {
            $operation1 = new BasicOperation();
            $operation2 = new BasicOperation();

            OperationFake::record(BasicOperation::class, $operation1);
            OperationFake::record(BasicOperation::class, $operation2);

            expect(OperationFake::executed())->toHaveCount(2);
        });
    });

    describe('Assert Dispatched', function (): void {
        test('passes when operation was dispatched', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(BasicOperation::class);

            expect(OperationFake::executed())->toHaveCount(1);
        });

        test('fails when operation was not dispatched', function (): void {
            OperationFake::assertDispatched(BasicOperation::class);
        })->throws(AssertionFailedError::class);

        test('passes with callback filter when operation matches', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(
                BasicOperation::class,
                fn ($op): bool => $op instanceof BasicOperation,
            );

            expect(OperationFake::executed())->toHaveCount(1);
        });

        test('fails with callback filter when operation does not match', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(
                BasicOperation::class,
                fn (): false => false,
            );
        })->throws(AssertionFailedError::class);
    });

    describe('Assert Not Dispatched', function (): void {
        test('passes when operation was not dispatched', function (): void {
            OperationFake::assertNotDispatched(BasicOperation::class);

            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('fails when operation was dispatched', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertNotDispatched(BasicOperation::class);
        })->throws(AssertionFailedError::class);

        test('passes with callback filter when operation does not match', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertNotDispatched(
                BasicOperation::class,
                fn (): false => false,
            );

            expect(OperationFake::executed())->toHaveCount(1);
        });

        test('fails with callback filter when operation matches', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertNotDispatched(
                BasicOperation::class,
                fn ($op): bool => $op instanceof BasicOperation,
            );
        })->throws(AssertionFailedError::class);
    });

    describe('Assert Dispatched Times', function (): void {
        test('passes when operation was dispatched exact number of times', function (): void {
            $operation1 = new BasicOperation();
            $operation2 = new BasicOperation();

            OperationFake::record(BasicOperation::class, $operation1);
            OperationFake::record(BasicOperation::class, $operation2);

            OperationFake::assertDispatchedTimes(BasicOperation::class, 2);

            expect(OperationFake::executed())->toHaveCount(2);
        });

        test('fails when operation count does not match', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatchedTimes(BasicOperation::class, 2);
        })->throws(AssertionFailedError::class);

        test('passes when checking zero times for non-dispatched operation', function (): void {
            OperationFake::assertDispatchedTimes(BasicOperation::class, 0);

            expect(OperationFake::executed())->toHaveCount(0);
        });
    });

    describe('Assert Nothing Dispatched', function (): void {
        test('passes when no operations were dispatched', function (): void {
            OperationFake::assertNothingDispatched();

            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('fails when operations were dispatched', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertNothingDispatched();
        })->throws(AssertionFailedError::class);
    });

    describe('Real-World Testing Scenarios', function (): void {
        test('tracks operation execution in test', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(BasicOperation::class);
            OperationFake::assertDispatchedTimes(BasicOperation::class, 1);
        });

        test('verifies operation not executed when condition fails', function (): void {
            // Simulate conditional operation that should not run
            OperationFake::assertNotDispatched(ConditionalOperation::class);

            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('tracks multiple operation types', function (): void {
            $operation1 = new BasicOperation();
            $operation2 = new IdempotentOperation();

            OperationFake::record(BasicOperation::class, $operation1);
            OperationFake::record(IdempotentOperation::class, $operation2);

            OperationFake::assertDispatched(BasicOperation::class);
            OperationFake::assertDispatched(IdempotentOperation::class);
        });
    });

    describe('Edge Cases and Advanced Scenarios', function (): void {
        test('handles empty executed array', function (): void {
            expect(OperationFake::executed())->toBeArray();
            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('maintains operation instance reference', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            $recorded = OperationFake::executed()[0]['operation'];
            expect($recorded)->toBe($operation);
        });

        test('records operations with exact class names', function (): void {
            $operation = new ConditionalOperation();
            OperationFake::record(ConditionalOperation::class, $operation);

            expect(OperationFake::executed()[0]['class'])->toBe(ConditionalOperation::class);
        });

        test('supports checking operations without callback', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(BasicOperation::class);
            expect(OperationFake::executed())->toHaveCount(1);
        });

        test('callback receives correct operation instance', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            $receivedOperation = null;
            OperationFake::assertDispatched(
                BasicOperation::class,
                function ($op) use (&$receivedOperation): true {
                    $receivedOperation = $op;

                    return true;
                },
            );

            expect($receivedOperation)->toBe($operation);
        });

        test('multiple assertions can be chained', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(BasicOperation::class);
            OperationFake::assertDispatchedTimes(BasicOperation::class, 1);
            OperationFake::assertNotDispatched(ConditionalOperation::class);
        });

        test('different operation instances are tracked separately', function (): void {
            $operation1 = new BasicOperation();
            $operation2 = new BasicOperation();
            $operation3 = new BasicOperation();

            OperationFake::record(BasicOperation::class, $operation1);
            OperationFake::record(BasicOperation::class, $operation2);
            OperationFake::record(BasicOperation::class, $operation3);

            expect(OperationFake::executed())->toHaveCount(3);
            OperationFake::assertDispatchedTimes(BasicOperation::class, 3);
        });

        test('can filter operations with complex callback logic', function (): void {
            $operation1 = new BasicOperation();
            $operation2 = new BasicOperation();

            OperationFake::record(BasicOperation::class, $operation1);
            OperationFake::record(BasicOperation::class, $operation2);

            // Only count specific instance
            OperationFake::assertDispatched(
                BasicOperation::class,
                fn ($op): bool => $op === $operation1,
            );
        });

        test('setup can be called multiple times safely', function (): void {
            OperationFake::setup();
            OperationFake::setup();
            OperationFake::setup();

            expect(OperationFake::isFaking())->toBeTrue();
            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('teardown can be called multiple times safely', function (): void {
            OperationFake::tearDown();
            OperationFake::tearDown();
            OperationFake::tearDown();

            expect(OperationFake::isFaking())->toBeFalse();
            expect(OperationFake::executed())->toHaveCount(0);
        });
    });

    describe('Cookbook Examples', function (): void {
        test('example from basic-usage: verifies operation dispatch', function (): void {
            // From cookbook: Testing Operations section
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(BasicOperation::class);
            OperationFake::assertDispatchedTimes(BasicOperation::class, 1);
            OperationFake::assertNotDispatched(ConditionalOperation::class);
        });

        test('example from basic-usage: tracks idempotent operations', function (): void {
            // Idempotent operations should be tracked just like regular operations
            $operation = new IdempotentOperation();
            OperationFake::record(IdempotentOperation::class, $operation);

            OperationFake::assertDispatched(IdempotentOperation::class);
        });

        test('validates operation execution count', function (): void {
            // Verify operation executed exactly once
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatchedTimes(BasicOperation::class, 1);
        });

        test('validates operation never executed', function (): void {
            // Verify operation was not executed
            OperationFake::assertDispatchedTimes(BasicOperation::class, 0);
            OperationFake::assertNotDispatched(BasicOperation::class);
        });
    });

    describe('Multiple Operation Type Tracking', function (): void {
        test('tracks basic and conditional operations separately', function (): void {
            $basic = new BasicOperation();
            $conditional = new ConditionalOperation();

            OperationFake::record(BasicOperation::class, $basic);
            OperationFake::record(ConditionalOperation::class, $conditional);

            OperationFake::assertDispatchedTimes(BasicOperation::class, 1);
            OperationFake::assertDispatchedTimes(ConditionalOperation::class, 1);
        });

        test('tracks basic and idempotent operations separately', function (): void {
            $basic = new BasicOperation();
            $idempotent = new IdempotentOperation();

            OperationFake::record(BasicOperation::class, $basic);
            OperationFake::record(IdempotentOperation::class, $idempotent);

            OperationFake::assertDispatchedTimes(BasicOperation::class, 1);
            OperationFake::assertDispatchedTimes(IdempotentOperation::class, 1);
        });

        test('tracks all three operation types in complex scenario', function (): void {
            OperationFake::record(BasicOperation::class, new BasicOperation());
            OperationFake::record(ConditionalOperation::class, new ConditionalOperation());
            OperationFake::record(IdempotentOperation::class, new IdempotentOperation());
            OperationFake::record(BasicOperation::class, new BasicOperation());

            expect(OperationFake::executed())->toHaveCount(4);
            OperationFake::assertDispatchedTimes(BasicOperation::class, 2);
            OperationFake::assertDispatchedTimes(ConditionalOperation::class, 1);
            OperationFake::assertDispatchedTimes(IdempotentOperation::class, 1);
        });
    });

    describe('Callback Filter Edge Cases', function (): void {
        test('callback returning true counts operation', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(
                BasicOperation::class,
                fn (): true => true,
            );
        });

        test('callback returning false does not count operation', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertNotDispatched(
                BasicOperation::class,
                fn (): false => false,
            );
        });

        test('callback can check operation properties', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(
                BasicOperation::class,
                fn ($op): bool => $op instanceof BasicOperation,
            );
        });

        test('callback filters out non-matching operations', function (): void {
            $operation1 = new BasicOperation();
            $operation2 = new BasicOperation();

            OperationFake::record(BasicOperation::class, $operation1);
            OperationFake::record(BasicOperation::class, $operation2);

            // Only the first one should match
            OperationFake::assertDispatched(
                BasicOperation::class,
                fn ($op): bool => $op === $operation1,
            );
        });
    });

    describe('State Management', function (): void {
        test('state is isolated between setup calls', function (): void {
            $operation1 = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation1);

            expect(OperationFake::executed())->toHaveCount(1);

            OperationFake::setup();

            expect(OperationFake::executed())->toHaveCount(0);
        });

        test('faking state persists between recordings', function (): void {
            expect(OperationFake::isFaking())->toBeTrue();

            OperationFake::record(BasicOperation::class, new BasicOperation());

            expect(OperationFake::isFaking())->toBeTrue();
        });

        test('teardown resets all state', function (): void {
            OperationFake::record(BasicOperation::class, new BasicOperation());
            OperationFake::record(ConditionalOperation::class, new ConditionalOperation());

            expect(OperationFake::executed())->toHaveCount(2);
            expect(OperationFake::isFaking())->toBeTrue();

            OperationFake::tearDown();

            expect(OperationFake::executed())->toHaveCount(0);
            expect(OperationFake::isFaking())->toBeFalse();
        });
    });

    describe('Integration Patterns', function (): void {
        test('verifies deployment scenario with multiple operations', function (): void {
            // Simulates a deployment with multiple operations
            OperationFake::record(BasicOperation::class, new BasicOperation());
            OperationFake::record(IdempotentOperation::class, new IdempotentOperation());

            OperationFake::assertDispatched(BasicOperation::class);
            OperationFake::assertDispatched(IdempotentOperation::class);
            expect(OperationFake::executed())->toHaveCount(2);
        });

        test('verifies conditional operation skipped in non-production', function (): void {
            // Conditional operation should not be dispatched in test environment
            OperationFake::assertNotDispatched(ConditionalOperation::class);
            OperationFake::assertDispatchedTimes(ConditionalOperation::class, 0);
        });

        test('tracks execution order through recorded array', function (): void {
            OperationFake::record(BasicOperation::class, new BasicOperation());
            OperationFake::record(ConditionalOperation::class, new ConditionalOperation());
            OperationFake::record(IdempotentOperation::class, new IdempotentOperation());

            $executed = OperationFake::executed();

            expect($executed[0]['class'])->toBe(BasicOperation::class);
            expect($executed[1]['class'])->toBe(ConditionalOperation::class);
            expect($executed[2]['class'])->toBe(IdempotentOperation::class);
        });

        test('supports asserting specific operation not in sequence', function (): void {
            OperationFake::record(BasicOperation::class, new BasicOperation());
            OperationFake::record(IdempotentOperation::class, new IdempotentOperation());

            OperationFake::assertNotDispatched(ConditionalOperation::class);
            expect(OperationFake::executed())->toHaveCount(2);
        });
    });
});
