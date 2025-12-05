<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\InvalidPrimaryKeyValueException;
use Cline\Sequencer\Exceptions\InvalidUlidPrimaryKeyValueException;
use Cline\Sequencer\Exceptions\InvalidUuidPrimaryKeyValueException;

/**
 * InvalidPrimaryKeyValueException Test Suite
 *
 * Tests exception for invalid primary key value assignments.
 */
describe('InvalidPrimaryKeyValueException', function (): void {
    describe('Happy Path - UUID Exception Creation', function (): void {
        test('creates exception with nonStringUuid factory method for integer value', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception)->toBeInstanceOf(InvalidUuidPrimaryKeyValueException::class);
            expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
        });

        test('exception message includes type information for integer', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: integer');
        });

        test('exception message includes type information for array', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(['id' => 1]);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: array');
        });

        test('exception message includes type information for object', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(
                new stdClass(),
            );

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: object');
        });

        test('exception message includes type information for null', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(null);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: NULL');
        });

        test('exception message includes type information for boolean', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(true);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: boolean');
        });

        test('exception message includes type information for double', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(3.14);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: double');
        });

        test('exception is instance of InvalidArgumentException', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception)->toBeInstanceOf(InvalidArgumentException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(InvalidPrimaryKeyValueException::class);
        });
    });

    describe('Happy Path - ULID Exception Creation', function (): void {
        test('creates exception with nonStringUlid factory method for integer value', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Assert
            expect($exception)->toBeInstanceOf(InvalidUlidPrimaryKeyValueException::class);
            expect($exception)->toBeInstanceOf(InvalidPrimaryKeyValueException::class);
        });

        test('exception message includes type information for integer', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: integer');
        });

        test('exception message includes type information for array', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(['id' => 1]);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: array');
        });

        test('exception message includes type information for object', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(
                new stdClass(),
            );

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: object');
        });

        test('exception message includes type information for null', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(null);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: NULL');
        });

        test('exception message includes type information for boolean', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(false);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: boolean');
        });

        test('exception message includes type information for double', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(2.718);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: double');
        });

        test('exception is instance of InvalidArgumentException', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Assert
            expect($exception)->toBeInstanceOf(InvalidArgumentException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(InvalidPrimaryKeyValueException::class);
        });
    });

    describe('Edge Cases - UUID Exceptions', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw InvalidUuidPrimaryKeyValueException::fromValue(123);
            } catch (InvalidUuidPrimaryKeyValueException $invalidUuidPrimaryKeyValueException) {
                // Assert
                expect($invalidUuidPrimaryKeyValueException->getTrace())->toBeArray();
                expect($invalidUuidPrimaryKeyValueException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect((string) $exception)->toContain('Cannot assign non-string value to UUID primary key');
        });

        test('handles resource type correctly', function (): void {
            // Arrange
            $resource = fopen('php://memory', 'rb');

            // Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue($resource);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: resource');

            fclose($resource);
        });

        test('handles closed resource type correctly', function (): void {
            // Arrange
            $resource = fopen('php://memory', 'rb');
            fclose($resource);

            // Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue($resource);

            // Assert
            expect($exception->getMessage())->toContain('Cannot assign non-string value to UUID primary key. Got: resource');
        });
    });

    describe('Edge Cases - ULID Exceptions', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw InvalidUlidPrimaryKeyValueException::fromValue(456);
            } catch (InvalidUlidPrimaryKeyValueException $invalidUlidPrimaryKeyValueException) {
                // Assert
                expect($invalidUlidPrimaryKeyValueException->getTrace())->toBeArray();
                expect($invalidUlidPrimaryKeyValueException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Assert
            expect((string) $exception)->toContain('Cannot assign non-string value to ULID primary key');
        });

        test('handles resource type correctly', function (): void {
            // Arrange
            $resource = fopen('php://memory', 'rb');

            // Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue($resource);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: resource');

            fclose($resource);
        });

        test('handles closed resource type correctly', function (): void {
            // Arrange
            $resource = fopen('php://memory', 'rb');
            fclose($resource);

            // Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue($resource);

            // Assert
            expect($exception->getMessage())->toContain('Cannot assign non-string value to ULID primary key. Got: resource');
        });
    });

    describe('Edge Cases - Different Value Types', function (): void {
        test('handles empty array for UUID', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue([]);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: array');
        });

        test('handles nested array for UUID', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue([['nested' => 'value']]);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: array');
        });

        test('handles empty array for ULID', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue([]);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: array');
        });

        test('handles nested array for ULID', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue([['nested' => 'value']]);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: array');
        });

        test('handles zero integer for UUID', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(0);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: integer');
        });

        test('handles negative integer for UUID', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(-123);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to UUID primary key. Got: integer');
        });

        test('handles zero integer for ULID', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(0);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: integer');
        });

        test('handles negative integer for ULID', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(-456);

            // Assert
            expect($exception->getMessage())->toBe('Cannot assign non-string value to ULID primary key. Got: integer');
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('UUID exception can be caught with InvalidArgumentException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw InvalidUuidPrimaryKeyValueException::fromValue(123))
                ->toThrow(InvalidArgumentException::class);
        });

        test('ULID exception can be caught with InvalidArgumentException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw InvalidUlidPrimaryKeyValueException::fromValue(456))
                ->toThrow(InvalidArgumentException::class);
        });

        test('UUID exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw InvalidUuidPrimaryKeyValueException::fromValue(123))
                ->toThrow(Exception::class);
        });

        test('ULID exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw InvalidUlidPrimaryKeyValueException::fromValue(456))
                ->toThrow(Exception::class);
        });

        test('UUID exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });

        test('ULID exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(456);

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Message Format Consistency', function (): void {
        test('UUID and ULID messages have consistent format', function (): void {
            // Arrange & Act
            $uuidException = InvalidUuidPrimaryKeyValueException::fromValue(123);
            $ulidException = InvalidUlidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($uuidException->getMessage())->toContain('Cannot assign non-string value to');
            expect($ulidException->getMessage())->toContain('Cannot assign non-string value to');
            expect($uuidException->getMessage())->toContain('primary key. Got:');
            expect($ulidException->getMessage())->toContain('primary key. Got:');
        });

        test('UUID message mentions UUID specifically', function (): void {
            // Arrange & Act
            $exception = InvalidUuidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception->getMessage())->toContain('UUID');
        });

        test('ULID message mentions ULID specifically', function (): void {
            // Arrange & Act
            $exception = InvalidUlidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($exception->getMessage())->toContain('ULID');
        });

        test('messages are different between UUID and ULID', function (): void {
            // Arrange & Act
            $uuidException = InvalidUuidPrimaryKeyValueException::fromValue(123);
            $ulidException = InvalidUlidPrimaryKeyValueException::fromValue(123);

            // Assert
            expect($uuidException->getMessage())->not->toBe($ulidException->getMessage());
        });
    });
});
