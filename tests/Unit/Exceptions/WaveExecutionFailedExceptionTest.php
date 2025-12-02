<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\WaveExecutionFailedException;

/**
 * WaveExecutionFailedException Test Suite
 *
 * Tests exception for wave execution failures.
 */
describe('WaveExecutionFailedException', function (): void {
    describe('Happy Path - Exception Creation', function (): void {
        test('creates exception with forWave factory method', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(1, 5);

            // Assert
            expect($exception)->toBeInstanceOf(WaveExecutionFailedException::class);
        });

        test('exception message includes wave number and failed jobs count', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(3, 10);

            // Assert
            expect($exception->getMessage())->toBe('Wave 3 failed with 10 failed jobs');
        });

        test('exception is instance of RuntimeException', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(1, 5);

            // Assert
            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        test('exception can be thrown and caught', function (): void {
            // Arrange
            $exception = WaveExecutionFailedException::forWave(1, 5);

            // Act & Assert
            expect(fn () => throw $exception)
                ->toThrow(WaveExecutionFailedException::class);
        });
    });

    describe('Edge Cases - Various Wave Numbers and Failed Job Counts', function (): void {
        test('handles wave 0 with 0 failed jobs', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(0, 0);

            // Assert
            expect($exception->getMessage())->toBe('Wave 0 failed with 0 failed jobs');
        });

        test('handles wave 1 with single failed job', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(1, 1);

            // Assert
            expect($exception->getMessage())->toBe('Wave 1 failed with 1 failed jobs');
        });

        test('handles high wave number', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(9_999, 42);

            // Assert
            expect($exception->getMessage())->toBe('Wave 9999 failed with 42 failed jobs');
        });

        test('handles large number of failed jobs', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(5, 10_000);

            // Assert
            expect($exception->getMessage())->toBe('Wave 5 failed with 10000 failed jobs');
        });

        test('handles negative wave number', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(-1, 3);

            // Assert
            expect($exception->getMessage())->toBe('Wave -1 failed with 3 failed jobs');
        });

        test('handles negative failed jobs count', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(2, -5);

            // Assert
            expect($exception->getMessage())->toBe('Wave 2 failed with -5 failed jobs');
        });
    });

    describe('Edge Cases - Exception Properties', function (): void {
        test('exception has default code zero', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(1, 5);

            // Assert
            expect($exception->getCode())->toBe(0);
        });

        test('exception has no previous exception by default', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(1, 5);

            // Assert
            expect($exception->getPrevious())->toBeNull();
        });

        test('exception trace is populated when thrown', function (): void {
            // Arrange & Act
            try {
                throw WaveExecutionFailedException::forWave(1, 5);
            } catch (WaveExecutionFailedException $waveExecutionFailedException) {
                // Assert
                expect($waveExecutionFailedException->getTrace())->toBeArray();
                expect($waveExecutionFailedException->getTrace())->not->toBeEmpty();
            }
        });

        test('exception string representation contains message', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(2, 7);

            // Assert
            expect((string) $exception)->toContain('Wave 2 failed with 7 failed jobs');
        });

        test('exception file property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw WaveExecutionFailedException::forWave(1, 5);
            } catch (WaveExecutionFailedException $waveExecutionFailedException) {
                // Assert
                expect($waveExecutionFailedException->getFile())->toBeString();
                expect($waveExecutionFailedException->getFile())->not->toBeEmpty();
            }
        });

        test('exception line property is set when thrown', function (): void {
            // Arrange & Act
            try {
                throw WaveExecutionFailedException::forWave(1, 5);
            } catch (WaveExecutionFailedException $waveExecutionFailedException) {
                // Assert
                expect($waveExecutionFailedException->getLine())->toBeInt();
                expect($waveExecutionFailedException->getLine())->toBeGreaterThan(0);
            }
        });
    });

    describe('Sad Path - Exception Handling', function (): void {
        test('exception can be caught with RuntimeException type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw WaveExecutionFailedException::forWave(1, 5))
                ->toThrow(RuntimeException::class);
        });

        test('exception can be caught with Exception type', function (): void {
            // Arrange & Act & Assert
            expect(fn () => throw WaveExecutionFailedException::forWave(1, 5))
                ->toThrow(Exception::class);
        });

        test('exception message cannot be empty', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(1, 5);

            // Assert
            expect($exception->getMessage())->not->toBeEmpty();
        });
    });

    describe('Edge Cases - Message Format', function (): void {
        test('message format is consistent', function (): void {
            // Arrange & Act
            $exception = WaveExecutionFailedException::forWave(3, 8);

            // Assert
            expect($exception->getMessage())->toStartWith('Wave');
            expect($exception->getMessage())->toContain('failed with');
            expect($exception->getMessage())->toContain('failed jobs');
        });

        test('message includes both wave number and failed jobs count', function (): void {
            // Arrange
            $waveNumber = 5;
            $failedJobs = 12;

            // Act
            $exception = WaveExecutionFailedException::forWave($waveNumber, $failedJobs);

            // Assert
            expect($exception->getMessage())->toContain((string) $waveNumber);
            expect($exception->getMessage())->toContain((string) $failedJobs);
        });

        test('different parameters produce different messages', function (): void {
            // Arrange & Act
            $exception1 = WaveExecutionFailedException::forWave(1, 5);
            $exception2 = WaveExecutionFailedException::forWave(2, 10);

            // Assert
            expect($exception1->getMessage())->not->toBe($exception2->getMessage());
        });

        test('same parameters produce same message', function (): void {
            // Arrange & Act
            $exception1 = WaveExecutionFailedException::forWave(3, 7);
            $exception2 = WaveExecutionFailedException::forWave(3, 7);

            // Assert
            expect($exception1->getMessage())->toBe($exception2->getMessage());
        });
    });
});
