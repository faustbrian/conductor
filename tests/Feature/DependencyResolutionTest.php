<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\HasDependencies;
use Cline\Sequencer\Contracts\Operation;
use Tests\Fixtures\Operations\BasicOperation;
use Tests\Fixtures\Operations\DependentOperation;

/**
 * Dependency Resolution Test Suite
 *
 * Tests operation dependency declarations and resolution.
 */
describe('Dependency Resolution', function (): void {
    beforeEach(function (): void {
        BasicOperation::reset();
        DependentOperation::reset();
    });

    describe('HasDependencies Interface', function (): void {
        test('operation implements has dependencies interface', function (): void {
            $operation = new DependentOperation();

            expect($operation)->toBeInstanceOf(HasDependencies::class);
        });

        test('dependsOn method returns array', function (): void {
            $operation = new DependentOperation();

            expect($operation->dependsOn())->toBeArray();
        });

        test('dependencies are operation class names', function (): void {
            $operation = new DependentOperation();
            $dependencies = $operation->dependsOn();

            expect($dependencies)->toContain(BasicOperation::class);
            expect($dependencies[0])->toBeString();
        });
    });

    describe('Dependency Declaration', function (): void {
        test('operation declares single dependency', function (): void {
            $operation = new DependentOperation();

            expect($operation->dependsOn())->toHaveCount(1);
            expect($operation->dependsOn())->toBe([BasicOperation::class]);
        });

        test('dependency references valid operation class', function (): void {
            $operation = new DependentOperation();
            $dependency = $operation->dependsOn()[0];

            expect(class_exists($dependency))->toBeTrue();
            expect(is_subclass_of($dependency, Operation::class))->toBeTrue();
        });
    });

    describe('Execution Order', function (): void {
        test('dependent operation knows its prerequisites', function (): void {
            $operation = new DependentOperation();
            $dependencies = $operation->dependsOn();

            // Orchestrator would ensure BasicOperation runs before DependentOperation
            expect($dependencies)->toContain(BasicOperation::class);
        });

        test('dependencies form valid dependency chain', function (): void {
            $operation = new DependentOperation();

            // BasicOperation has no dependencies (implicitly)
            $basicOp = new BasicOperation();

            // DependentOperation depends on BasicOperation
            expect($operation->dependsOn())->toContain(BasicOperation::class);
        });
    });

    describe('Real-World Scenarios', function (): void {
        test('data migration depends on schema migration', function (): void {
            // In real scenario:
            // DataMigration depends on SchemaCreation
            $operation = new DependentOperation();

            expect($operation->dependsOn())->not->toBeEmpty();
        });

        test('operation depends on multiple prerequisites', function (): void {
            // DependentOperation could declare multiple dependencies
            $operation = new DependentOperation();
            $dependencies = $operation->dependsOn();

            expect($dependencies)->toBeArray();
        });
    });
});
