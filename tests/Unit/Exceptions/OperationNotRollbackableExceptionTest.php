<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\OperationNotRollbackableException;

/**
 * OperationNotRollbackableException Test Suite
 *
 * Tests exception for operations that don't implement Rollbackable interface.
 */
describe('OperationNotRollbackableException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with doesNotImplementInterface factory method', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception)->toBeInstanceOf(OperationNotRollbackableException::class);
        });

        test('exception message indicates interface requirement', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception->getMessage())->toBe('Operation does not implement Rollbackable interface');
        });

        test('exception is instance of RuntimeException', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(OperationNotRollbackableException::class);
        });
    });

    describe('Edge Cases - Exception Properties', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationNotRollbackableException::doesNotImplementInterface();
            } catch (OperationNotRollbackableException $operationNotRollbackableException) {
                // Assert
                expect($operationNotRollbackableException->getTrace())->toBeArray();
                expect($operationNotRollbackableException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect((string) $exception)->toContain('Operation does not implement Rollbackable interface');
        });

        test('exception file property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationNotRollbackableException::doesNotImplementInterface();
            } catch (OperationNotRollbackableException $operationNotRollbackableException) {
                // Assert
                expect($operationNotRollbackableException->getFile())->toBeString();
                expect($operationNotRollbackableException->getFile())->not->toBeEmpty();
            }
        });

        test('exception line property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationNotRollbackableException::doesNotImplementInterface();
            } catch (OperationNotRollbackableException $operationNotRollbackableException) {
                // Assert
                expect($operationNotRollbackableException->getLine())->toBeInt();
                expect($operationNotRollbackableException->getLine())->toBeGreaterThan(0);
            }
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with RuntimeException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationNotRollbackableException::doesNotImplementInterface())
                ->toThrow(RuntimeException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationNotRollbackableException::doesNotImplementInterface())
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Message Format', function (): void {
        test('message mentions both operation and interface', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception->getMessage())->toContain('Operation');
            expect($exception->getMessage())->toContain('Rollbackable interface');
        });

        test('message uses consistent capitalization', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception->getMessage())->toStartWith('Operation');
        });

        test('message indicates negative condition clearly', function (): void {
            // Arrange & Act
            $exception = OperationNotRollbackableException::doesNotImplementInterface();

            // Assert
            expect($exception->getMessage())->toContain('does not implement');
        });
    });
});
