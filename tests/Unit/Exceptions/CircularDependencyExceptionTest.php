<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\CircularDependencyException;

/**
 * CircularDependencyException Test Suite
 *
 * Tests exception for circular dependency detection.
 */
describe('CircularDependencyException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with detected factory method', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect($exception)->toBeInstanceOf(CircularDependencyException::class);
        });

        test('exception message matches expected text exactly', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect($exception->getMessage())->toBe('Circular dependency detected - cannot build execution waves');
        });

        test('exception is instance of RuntimeException', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = CircularDependencyException::detected();

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(CircularDependencyException::class);
        });
    });

    describe('Edge Cases - Exception Behavior', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception file and line are set when thrown', function (): void {
            // Arrange & Act
            try {
                throw CircularDependencyException::detected();
            } catch (CircularDependencyException $circularDependencyException) {
                // Assert
                expect($circularDependencyException->getFile())->toBeString();
                expect($circularDependencyException->getLine())->toBeInt();
                expect($circularDependencyException->getLine())->toBeGreaterThan(0);
            }
        });

        test('multiple instances have same message', function (): void {
            // Arrange & Act
            $exception1 = CircularDependencyException::detected();
            $exception2 = CircularDependencyException::detected();

            // Assert
            expect($exception1->getMessage())->toBe($exception2->getMessage());
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw CircularDependencyException::detected();
            } catch (CircularDependencyException $circularDependencyException) {
                // Assert
                expect($circularDependencyException->getTrace())->toBeArray();
                expect($circularDependencyException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception trace as string is not empty', function (): void {
            // Arrange & Act
            try {
                throw CircularDependencyException::detected();
            } catch (CircularDependencyException $circularDependencyException) {
                // Assert
                expect($circularDependencyException->getTraceAsString())->toBeString();
                expect($circularDependencyException->getTraceAsString())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect((string) $exception)->toContain('Circular dependency detected - cannot build execution waves');
        });

        test('exception message describes circular dependency and execution waves', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect($exception->getMessage())->toContain('Circular dependency');
            expect($exception->getMessage())->toContain('execution waves');
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with RuntimeException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw CircularDependencyException::detected())
                ->toThrow(RuntimeException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw CircularDependencyException::detected())
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = CircularDependencyException::detected();

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });
});
