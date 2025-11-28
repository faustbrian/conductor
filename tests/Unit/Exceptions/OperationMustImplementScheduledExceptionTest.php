<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\OperationMustImplementScheduledException;

/**
 * OperationMustImplementScheduledException Test Suite
 *
 * Tests exception for operations that must implement Scheduled interface.
 */
describe('OperationMustImplementScheduledException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with forDispatch factory method', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception)->toBeInstanceOf(OperationMustImplementScheduledException::class);
        });

        test('exception message indicates interface requirement', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception->getMessage())->toBe('Operation must implement Scheduled interface');
        });

        test('exception is instance of LogicException', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception)->toBeInstanceOf(LogicException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(OperationMustImplementScheduledException::class);
        });
    });

    describe('Edge Cases - Exception Properties', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationMustImplementScheduledException::forDispatch();
            } catch (OperationMustImplementScheduledException $operationMustImplementScheduledException) {
                // Assert
                expect($operationMustImplementScheduledException->getTrace())->toBeArray();
                expect($operationMustImplementScheduledException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect((string) $exception)->toContain('Operation must implement Scheduled interface');
        });

        test('exception file property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationMustImplementScheduledException::forDispatch();
            } catch (OperationMustImplementScheduledException $operationMustImplementScheduledException) {
                // Assert
                expect($operationMustImplementScheduledException->getFile())->toBeString();
                expect($operationMustImplementScheduledException->getFile())->not->toBeEmpty();
            }
        });

        test('exception line property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationMustImplementScheduledException::forDispatch();
            } catch (OperationMustImplementScheduledException $operationMustImplementScheduledException) {
                // Assert
                expect($operationMustImplementScheduledException->getLine())->toBeInt();
                expect($operationMustImplementScheduledException->getLine())->toBeGreaterThan(0);
            }
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with LogicException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationMustImplementScheduledException::forDispatch())
                ->toThrow(LogicException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationMustImplementScheduledException::forDispatch())
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Message Format', function (): void {
        test('message mentions both operation and interface', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception->getMessage())->toContain('Operation');
            expect($exception->getMessage())->toContain('Scheduled interface');
        });

        test('message uses consistent capitalization', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception->getMessage())->toStartWith('Operation');
        });

        test('message indicates requirement clearly', function (): void {
            // Arrange & Act
            $exception = OperationMustImplementScheduledException::forDispatch();

            // Assert
            expect($exception->getMessage())->toContain('must implement');
        });
    });
});
