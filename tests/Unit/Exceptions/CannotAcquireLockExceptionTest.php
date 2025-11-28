<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\CannotAcquireLockException;

/**
 * CannotAcquireLockException Test Suite
 *
 * Tests exception for lock acquisition failures.
 */
describe('CannotAcquireLockException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with timeoutExceeded factory method', function (): void {
            // Arrange & Act
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect($exception)->toBeInstanceOf(CannotAcquireLockException::class);
        });

        test('exception message matches expected text exactly', function (): void {
            // Arrange & Act
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect($exception->getMessage())->toBe('Could not acquire sequencer lock within timeout period');
        });

        test('exception is instance of RuntimeException', function (): void {
            // Arrange & Act
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(CannotAcquireLockException::class);
        });
    });

    describe('Edge Cases - Exception Behavior', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception file and line are set when thrown', function (): void {
            // Arrange & Act
            try {
                throw CannotAcquireLockException::timeoutExceeded();
            } catch (CannotAcquireLockException $cannotAcquireLockException) {
                // Assert
                expect($cannotAcquireLockException->getFile())->toBeString();
                expect($cannotAcquireLockException->getLine())->toBeInt();
                expect($cannotAcquireLockException->getLine())->toBeGreaterThan(0);
            }
        });

        test('multiple instances have same message', function (): void {
            // Arrange & Act
            $exception1 = CannotAcquireLockException::timeoutExceeded();
            $exception2 = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect($exception1->getMessage())->toBe($exception2->getMessage());
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw CannotAcquireLockException::timeoutExceeded();
            } catch (CannotAcquireLockException $cannotAcquireLockException) {
                // Assert
                expect($cannotAcquireLockException->getTrace())->toBeArray();
                expect($cannotAcquireLockException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception trace as string is not empty', function (): void {
            // Arrange & Act
            try {
                throw CannotAcquireLockException::timeoutExceeded();
            } catch (CannotAcquireLockException $cannotAcquireLockException) {
                // Assert
                expect($cannotAcquireLockException->getTraceAsString())->toBeString();
                expect($cannotAcquireLockException->getTraceAsString())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect((string) $exception)->toContain('Could not acquire sequencer lock within timeout period');
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with RuntimeException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw CannotAcquireLockException::timeoutExceeded())
                ->toThrow(RuntimeException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw CannotAcquireLockException::timeoutExceeded())
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = CannotAcquireLockException::timeoutExceeded();

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });
});
