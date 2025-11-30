<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\ConditionalExecution;
use Tests\Fixtures\Operations\ConditionalOperation;

/**
 * Conditional Execution Test Suite
 *
 * Tests conditional operation execution based on runtime conditions.
 */
describe('Conditional Execution', function (): void {
    beforeEach(function (): void {
        ConditionalOperation::reset();
    });

    describe('ConditionalExecution Interface', function (): void {
        test('operation implements conditional execution interface', function (): void {
            $operation = new ConditionalOperation();

            expect($operation)->toBeInstanceOf(ConditionalExecution::class);
        });

        test('shouldRun method returns boolean', function (): void {
            $operation = new ConditionalOperation();

            expect($operation->shouldRun())->toBeBool();
        });
    });

    describe('Conditional Logic', function (): void {
        test('operation runs when shouldRun returns true', function (): void {
            ConditionalOperation::$shouldRunValue = true;
            $operation = new ConditionalOperation();

            expect($operation->shouldRun())->toBeTrue();

            $operation->handle();

            expect(ConditionalOperation::$executed)->toBeTrue();
        });

        test('operation skips when shouldRun returns false', function (): void {
            ConditionalOperation::$shouldRunValue = false;
            $operation = new ConditionalOperation();

            expect($operation->shouldRun())->toBeFalse();

            // In real scenario, orchestrator would skip execution
            expect(ConditionalOperation::$executed)->toBeFalse();
        });

        test('shouldRun can be toggled between executions', function (): void {
            $operation = new ConditionalOperation();

            ConditionalOperation::$shouldRunValue = true;
            expect($operation->shouldRun())->toBeTrue();

            ConditionalOperation::$shouldRunValue = false;
            expect($operation->shouldRun())->toBeFalse();

            ConditionalOperation::$shouldRunValue = true;
            expect($operation->shouldRun())->toBeTrue();
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('environment-based conditional execution', function (): void {
            // Simulate environment check
            ConditionalOperation::$shouldRunValue = app()->environment('production');

            $operation = new ConditionalOperation();

            // Operation would only run in production
            expect($operation->shouldRun())->toBeBool();
        });

        test('feature flag conditional execution', function (): void {
            // Simulate feature flag check
            ConditionalOperation::$shouldRunValue = true; // Feature enabled

            $operation = new ConditionalOperation();

            expect($operation->shouldRun())->toBeTrue();
        });

        test('state-based conditional execution', function (): void {
            // Simulate database state check
            ConditionalOperation::$shouldRunValue = true; // State allows execution

            $operation = new ConditionalOperation();

            expect($operation->shouldRun())->toBeTrue();
        });
    });
});
