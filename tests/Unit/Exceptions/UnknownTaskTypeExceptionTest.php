<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\UnknownTaskTypeException;

/**
 * UnknownTaskTypeException Test Suite
 *
 * Tests exception for unknown task type scenarios.
 */
describe('UnknownTaskTypeException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with forType factory method', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('invalid-type');

            // Assert
            expect($exception)->toBeInstanceOf(UnknownTaskTypeException::class);
        });

        test('exception message includes task type', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('sync-users');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: sync-users');
        });

        test('exception is instance of LogicException', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('invalid-type');

            // Assert
            expect($exception)->toBeInstanceOf(LogicException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = UnknownTaskTypeException::forType('invalid-type');

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(UnknownTaskTypeException::class);
        });
    });

    describe('Edge Cases - Various Task Types', function (): void {
        test('handles simple task type', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('simple');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: simple');
        });

        test('handles task type with hyphens', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('sync-users-data');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: sync-users-data');
        });

        test('handles task type with underscores', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('sync_users_data');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: sync_users_data');
        });

        test('handles empty string task type', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: ');
        });

        test('handles task type with spaces', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('task with spaces');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: task with spaces');
        });

        test('handles task type with numbers', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('task123');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: task123');
        });

        test('handles task type with special characters', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('task.type:special');

            // Assert
            expect($exception->getMessage())->toBe('Unknown task type: task.type:special');
        });
    });

    describe('Edge Cases - Exception Properties', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('invalid-type');

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('invalid-type');

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw UnknownTaskTypeException::forType('invalid-type');
            } catch (UnknownTaskTypeException $unknownTaskTypeException) {
                // Assert
                expect($unknownTaskTypeException->getTrace())->toBeArray();
                expect($unknownTaskTypeException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('invalid-type');

            // Assert
            expect((string) $exception)->toContain('Unknown task type: invalid-type');
        });

        test('exception file property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw UnknownTaskTypeException::forType('invalid-type');
            } catch (UnknownTaskTypeException $unknownTaskTypeException) {
                // Assert
                expect($unknownTaskTypeException->getFile())->toBeString();
                expect($unknownTaskTypeException->getFile())->not->toBeEmpty();
            }
        });

        test('exception line property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw UnknownTaskTypeException::forType('invalid-type');
            } catch (UnknownTaskTypeException $unknownTaskTypeException) {
                // Assert
                expect($unknownTaskTypeException->getLine())->toBeInt();
                expect($unknownTaskTypeException->getLine())->toBeGreaterThan(0);
            }
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with LogicException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw UnknownTaskTypeException::forType('invalid-type'))
                ->toThrow(LogicException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw UnknownTaskTypeException::forType('invalid-type'))
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty when type provided', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('invalid-type');

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Message Format', function (): void {
        test('message format is consistent', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('test-type');

            // Assert
            expect($exception->getMessage())->toStartWith('Unknown task type:');
        });

        test('message includes the provided task type', function (): void {
            // Arrange
            $taskType = 'custom-task';

            // Act
            $exception = UnknownTaskTypeException::forType($taskType);

            // Assert
            expect($exception->getMessage())->toContain($taskType);
        });

        test('different task types produce different messages', function (): void {
            // Arrange & Act
            $exception1 = UnknownTaskTypeException::forType('type1');
            $exception2 = UnknownTaskTypeException::forType('type2');

            // Assert
            expect($exception1->getMessage())->not->toBe($exception2->getMessage());
        });

        test('message uses consistent capitalization', function (): void {
            // Arrange & Act
            $exception = UnknownTaskTypeException::forType('test-type');

            // Assert
            expect($exception->getMessage())->toStartWith('Unknown');
        });
    });
});
