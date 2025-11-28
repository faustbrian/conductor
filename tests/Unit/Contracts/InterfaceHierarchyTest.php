<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\Asynchronous;
use Cline\Sequencer\Contracts\ConditionalExecution;
use Cline\Sequencer\Contracts\HasDependencies;
use Cline\Sequencer\Contracts\Idempotent;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Processable;
use Cline\Sequencer\Contracts\Rollbackable;
use Cline\Sequencer\Contracts\WithinTransaction;

describe('Interface Hierarchy', function (): void {
    describe('Processable Interface', function (): void {
        test('is the base interface', function (): void {
            $reflection = new ReflectionClass(Processable::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->getInterfaces())->toBeEmpty();
        });

        test('declares handle method', function (): void {
            $reflection = new ReflectionClass(Processable::class);
            $methods = $reflection->getMethods();

            expect($methods)->toHaveCount(1);
            expect($methods[0]->getName())->toBe('handle');
        });
    });

    describe('Operation Interface', function (): void {
        test('extends Processable', function (): void {
            $reflection = new ReflectionClass(Operation::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->implementsInterface(Processable::class))->toBeTrue();
        });

        test('inherits handle method from Processable', function (): void {
            $reflection = new ReflectionClass(Operation::class);

            expect($reflection->hasMethod('handle'))->toBeTrue();
        });
    });

    describe('Rollbackable Interface', function (): void {
        test('extends Operation', function (): void {
            $reflection = new ReflectionClass(Rollbackable::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->implementsInterface(Operation::class))->toBeTrue();
        });

        test('declares rollback method', function (): void {
            $reflection = new ReflectionClass(Rollbackable::class);

            expect($reflection->hasMethod('rollback'))->toBeTrue();
        });

        test('inherits handle from Processable', function (): void {
            expect(
                new ReflectionClass(Rollbackable::class)->hasMethod('handle'),
            )->toBeTrue();
        });
    });

    describe('HasDependencies Interface', function (): void {
        test('extends Operation', function (): void {
            $reflection = new ReflectionClass(HasDependencies::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->implementsInterface(Operation::class))->toBeTrue();
        });

        test('declares dependsOn method', function (): void {
            $reflection = new ReflectionClass(HasDependencies::class);

            expect($reflection->hasMethod('dependsOn'))->toBeTrue();
        });
    });

    describe('ConditionalExecution Interface', function (): void {
        test('extends Operation', function (): void {
            $reflection = new ReflectionClass(ConditionalExecution::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->implementsInterface(Operation::class))->toBeTrue();
        });

        test('declares shouldRun method', function (): void {
            $reflection = new ReflectionClass(ConditionalExecution::class);

            expect($reflection->hasMethod('shouldRun'))->toBeTrue();
        });
    });

    describe('Idempotent Interface', function (): void {
        test('extends Operation', function (): void {
            $reflection = new ReflectionClass(Idempotent::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->implementsInterface(Operation::class))->toBeTrue();
        });

        test('is a marker interface with no methods', function (): void {
            $reflection = new ReflectionClass(Idempotent::class);
            $ownMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            // Filter out inherited methods
            $declaredMethods = array_filter(
                $ownMethods,
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Idempotent::class,
            );

            expect($declaredMethods)->toBeEmpty();
        });
    });

    describe('WithinTransaction Interface', function (): void {
        test('extends Operation', function (): void {
            $reflection = new ReflectionClass(WithinTransaction::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->implementsInterface(Operation::class))->toBeTrue();
        });

        test('is a marker interface with no methods', function (): void {
            $reflection = new ReflectionClass(WithinTransaction::class);
            $ownMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            $declaredMethods = array_filter(
                $ownMethods,
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === WithinTransaction::class,
            );

            expect($declaredMethods)->toBeEmpty();
        });
    });

    describe('Asynchronous Interface', function (): void {
        test('extends Operation', function (): void {
            $reflection = new ReflectionClass(Asynchronous::class);

            expect($reflection->isInterface())->toBeTrue();
            expect($reflection->implementsInterface(Operation::class))->toBeTrue();
        });

        test('is a marker interface with no methods', function (): void {
            $reflection = new ReflectionClass(Asynchronous::class);
            $ownMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            $declaredMethods = array_filter(
                $ownMethods,
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Asynchronous::class,
            );

            expect($declaredMethods)->toBeEmpty();
        });
    });

    describe('Complete Hierarchy', function (): void {
        test('all operation interfaces extend Operation', function (): void {
            $operationInterfaces = [
                Rollbackable::class,
                HasDependencies::class,
                ConditionalExecution::class,
                Idempotent::class,
                WithinTransaction::class,
                Asynchronous::class,
            ];

            foreach ($operationInterfaces as $interface) {
                expect(
                    new ReflectionClass($interface)->implementsInterface(Operation::class),
                )
                    ->toBeTrue(sprintf('Expected %s to extend Operation', $interface));
            }
        });

        test('all operation interfaces extend Processable transitively', function (): void {
            $operationInterfaces = [
                Operation::class,
                Rollbackable::class,
                HasDependencies::class,
                ConditionalExecution::class,
                Idempotent::class,
                WithinTransaction::class,
                Asynchronous::class,
            ];

            foreach ($operationInterfaces as $interface) {
                expect(
                    new ReflectionClass($interface)->implementsInterface(Processable::class),
                )
                    ->toBeTrue(sprintf('Expected %s to extend Processable', $interface));
            }
        });
    });
});
