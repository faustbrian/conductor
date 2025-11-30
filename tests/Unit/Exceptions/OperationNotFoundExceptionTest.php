<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\OperationNotFoundException;

/**
 * OperationNotFoundException Test Suite
 *
 * Tests exception for operation not found scenarios.
 */
describe('OperationNotFoundException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with forOperation factory method', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('SomeOperation');

            // Assert
            expect($exception)->toBeInstanceOf(OperationNotFoundException::class);
        });

        test('exception message includes operation name', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('CreateUserOperation');

            // Assert
            expect($exception->getMessage())->toBe('Operation not found: CreateUserOperation');
        });

        test('exception is instance of RuntimeException', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('SomeOperation');

            // Assert
            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = OperationNotFoundException::forOperation('SomeOperation');

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(OperationNotFoundException::class);
        });
    });

    describe('Edge Cases - Various Operation Names', function (): void {
        test('handles simple operation name', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('SimpleOp');

            // Assert
            expect($exception->getMessage())->toBe('Operation not found: SimpleOp');
        });

        test('handles fully qualified class name', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('App\\Operations\\CreateUserOperation');

            // Assert
            expect($exception->getMessage())->toBe('Operation not found: App\\Operations\\CreateUserOperation');
        });

        test('handles empty string operation name', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('');

            // Assert
            expect($exception->getMessage())->toBe('Operation not found: ');
        });

        test('handles operation name with special characters', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('Operation-With-Dashes_And_Underscores');

            // Assert
            expect($exception->getMessage())->toBe('Operation not found: Operation-With-Dashes_And_Underscores');
        });

        test('handles operation name with spaces', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('Operation With Spaces');

            // Assert
            expect($exception->getMessage())->toBe('Operation not found: Operation With Spaces');
        });

        test('handles operation name with numbers', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('Operation123');

            // Assert
            expect($exception->getMessage())->toBe('Operation not found: Operation123');
        });
    });

    describe('Edge Cases - Exception Properties', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('SomeOperation');

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('SomeOperation');

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationNotFoundException::forOperation('SomeOperation');
            } catch (OperationNotFoundException $operationNotFoundException) {
                // Assert
                expect($operationNotFoundException->getTrace())->toBeArray();
                expect($operationNotFoundException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('SomeOperation');

            // Assert
            expect((string) $exception)->toContain('Operation not found: SomeOperation');
        });

        test('exception file property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationNotFoundException::forOperation('SomeOperation');
            } catch (OperationNotFoundException $operationNotFoundException) {
                // Assert
                expect($operationNotFoundException->getFile())->toBeString();
                expect($operationNotFoundException->getFile())->not->toBeEmpty();
            }
        });

        test('exception line property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationNotFoundException::forOperation('SomeOperation');
            } catch (OperationNotFoundException $operationNotFoundException) {
                // Assert
                expect($operationNotFoundException->getLine())->toBeInt();
                expect($operationNotFoundException->getLine())->toBeGreaterThan(0);
            }
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with RuntimeException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationNotFoundException::forOperation('SomeOperation'))
                ->toThrow(RuntimeException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationNotFoundException::forOperation('SomeOperation'))
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty when operation name provided', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('SomeOperation');

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Message Format', function (): void {
        test('message format is consistent', function (): void {
            // Arrange & Act
            $exception = OperationNotFoundException::forOperation('TestOp');

            // Assert
            expect($exception->getMessage())->toStartWith('Operation not found:');
        });

        test('message includes the provided operation name', function (): void {
            // Arrange
            $operationName = 'CustomOperation';

            // Act
            $exception = OperationNotFoundException::forOperation($operationName);

            // Assert
            expect($exception->getMessage())->toContain($operationName);
        });

        test('different operation names produce different messages', function (): void {
            // Arrange & Act
            $exception1 = OperationNotFoundException::forOperation('Operation1');
            $exception2 = OperationNotFoundException::forOperation('Operation2');

            // Assert
            expect($exception1->getMessage())->not->toBe($exception2->getMessage());
        });
    });
});
