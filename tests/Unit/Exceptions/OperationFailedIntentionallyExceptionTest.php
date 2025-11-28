<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\OperationFailedIntentionallyException;

/**
 * OperationFailedIntentionallyException Test Suite
 *
 * Tests exception for intentional operation failures.
 */
describe('OperationFailedIntentionallyException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with create factory method', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception)->toBeInstanceOf(OperationFailedIntentionallyException::class);
        });

        test('exception message matches expected text exactly', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception->getMessage())->toBe('Operation failed intentionally');
        });

        test('exception is instance of RuntimeException', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = OperationFailedIntentionallyException::create();

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(OperationFailedIntentionallyException::class);
        });
    });

    describe('Edge Cases - Exception Behavior', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception file and line are set when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationFailedIntentionallyException::create();
            } catch (OperationFailedIntentionallyException $operationFailedIntentionallyException) {
                // Assert
                expect($operationFailedIntentionallyException->getFile())->toBeString();
                expect($operationFailedIntentionallyException->getLine())->toBeInt();
                expect($operationFailedIntentionallyException->getLine())->toBeGreaterThan(0);
            }
        });

        test('multiple instances have same message', function (): void {
            // Arrange & Act
            $exception1 = OperationFailedIntentionallyException::create();
            $exception2 = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception1->getMessage())->toBe($exception2->getMessage());
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw OperationFailedIntentionallyException::create();
            } catch (OperationFailedIntentionallyException $operationFailedIntentionallyException) {
                // Assert
                expect($operationFailedIntentionallyException->getTrace())->toBeArray();
                expect($operationFailedIntentionallyException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception trace as string is not empty', function (): void {
            // Arrange & Act
            try {
                throw OperationFailedIntentionallyException::create();
            } catch (OperationFailedIntentionallyException $operationFailedIntentionallyException) {
                // Assert
                expect($operationFailedIntentionallyException->getTraceAsString())->toBeString();
                expect($operationFailedIntentionallyException->getTraceAsString())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect((string) $exception)->toContain('Operation failed intentionally');
        });

        test('exception message indicates intentional failure', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception->getMessage())->toContain('intentionally');
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with RuntimeException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationFailedIntentionallyException::create())
                ->toThrow(RuntimeException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw OperationFailedIntentionallyException::create())
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = OperationFailedIntentionallyException::create();

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Testing Use Cases', function (): void {
        test('can be used to test error handling in operations', function (): void {
            // Arrange
            $exceptionThrown = false;

            // Act
            try {
                throw OperationFailedIntentionallyException::create();
            } catch (OperationFailedIntentionallyException) {
                $exceptionThrown = true;
            }

            // Assert
            expect($exceptionThrown)->toBeTrue();
        });

        test('exception type is distinguishable from other RuntimeExceptions', function (): void {
            // Arrange
            $intentionalException = OperationFailedIntentionallyException::create();
            $genericException = new RuntimeException('Generic error');

            // Act & Assert
            expect($intentionalException)->toBeInstanceOf(OperationFailedIntentionallyException::class);
            expect($intentionalException::class)->toBe(OperationFailedIntentionallyException::class);
            expect($intentionalException::class)->not->toBe(RuntimeException::class);
        });

        test('can identify intentional failures in catch blocks', function (): void {
            // Arrange
            $isIntentionalFailure = false;

            // Act
            try {
                throw OperationFailedIntentionallyException::create();
            } catch (RuntimeException $runtimeException) {
                if ($runtimeException instanceof OperationFailedIntentionallyException) {
                    $isIntentionalFailure = true;
                }
            }

            // Assert
            expect($isIntentionalFailure)->toBeTrue();
        });
    });
});
