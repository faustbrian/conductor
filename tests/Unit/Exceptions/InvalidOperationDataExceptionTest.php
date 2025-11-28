<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\InvalidOperationDataException;

/**
 * InvalidOperationDataException Test Suite
 *
 * Tests exception for invalid operation data.
 */
describe('InvalidOperationDataException', function (): void {
    describe('Happy Path - Missing Class Exception Creation', function (): void {
        test('creates exception with missingClass factory method', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception)->toBeInstanceOf(InvalidOperationDataException::class);
        });

        test('exception message indicates missing class', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception->getMessage())->toBe('Operation data missing class');
        });

        test('exception is instance of LogicException', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception)->toBeInstanceOf(LogicException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = InvalidOperationDataException::missingClass();

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(InvalidOperationDataException::class);
        });
    });

    describe('Edge Cases - Exception Properties', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw InvalidOperationDataException::missingClass();
            } catch (InvalidOperationDataException $invalidOperationDataException) {
                // Assert
                expect($invalidOperationDataException->getTrace())->toBeArray();
                expect($invalidOperationDataException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect((string) $exception)->toContain('Operation data missing class');
        });

        test('exception file property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw InvalidOperationDataException::missingClass();
            } catch (InvalidOperationDataException $invalidOperationDataException) {
                // Assert
                expect($invalidOperationDataException->getFile())->toBeString();
                expect($invalidOperationDataException->getFile())->not->toBeEmpty();
            }
        });

        test('exception line property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw InvalidOperationDataException::missingClass();
            } catch (InvalidOperationDataException $invalidOperationDataException) {
                // Assert
                expect($invalidOperationDataException->getLine())->toBeInt();
                expect($invalidOperationDataException->getLine())->toBeGreaterThan(0);
            }
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with LogicException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw InvalidOperationDataException::missingClass())
                ->toThrow(LogicException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw InvalidOperationDataException::missingClass())
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Message Format', function (): void {
        test('message is specific and descriptive', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception->getMessage())->toContain('Operation data');
            expect($exception->getMessage())->toContain('missing class');
        });

        test('message uses consistent capitalization', function (): void {
            // Arrange & Act
            $exception = InvalidOperationDataException::missingClass();

            // Assert
            expect($exception->getMessage())->toStartWith('Operation');
        });
    });
});
