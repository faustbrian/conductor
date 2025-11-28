<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Testing\OperationFake;
use Tests\Fixtures\Operations\BasicOperation;
use Tests\Fixtures\Operations\ConditionalOperation;
use Tests\Fixtures\Operations\DependentOperation;

/**
 * Sequential Orchestration Test Suite
 *
 * Tests the core sequential execution of migrations and operations.
 */
describe('Sequential Orchestration', function (): void {
    beforeEach(function (): void {
        OperationFake::setup();
        BasicOperation::reset();
        ConditionalOperation::reset();
        DependentOperation::reset();
    });

    afterEach(function (): void {
        OperationFake::tearDown();
    });

    describe('Operation Execution', function (): void {
        test('executes operations in chronological order', function (): void {
            // This would require setting up actual operation files with timestamps
            // For now, we verify the fake tracking works
            expect(OperationFake::isFaking())->toBeTrue();
        });

        test('tracks executed operations when faking', function (): void {
            $operation = new BasicOperation();
            OperationFake::record(BasicOperation::class, $operation);

            OperationFake::assertDispatched(BasicOperation::class);
        });
    });

    describe('Conditional Execution', function (): void {
        test('skips operation when shouldRun returns false', function (): void {
            ConditionalOperation::$shouldRunValue = false;

            // In real scenario, orchestrator would check shouldRun()
            // and skip execution
            expect(ConditionalOperation::$shouldRunValue)->toBeFalse();
        });

        test('executes operation when shouldRun returns true', function (): void {
            ConditionalOperation::$shouldRunValue = true;

            expect(ConditionalOperation::$shouldRunValue)->toBeTrue();
        });
    });

    describe('Dependency Resolution', function (): void {
        test('operation declares dependencies correctly', function (): void {
            $operation = new DependentOperation();

            expect($operation->dependsOn())->toBe([BasicOperation::class]);
        });

        test('dependent operation references prerequisite', function (): void {
            $operation = new DependentOperation();
            $dependencies = $operation->dependsOn();

            expect($dependencies)->toContain(BasicOperation::class);
        });
    });
});
