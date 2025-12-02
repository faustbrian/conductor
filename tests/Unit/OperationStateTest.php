<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Enums\OperationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;

uses(RefreshDatabase::class);

describe('OperationState Enum', function (): void {
    describe('state attribute casting', function (): void {
        test('casts state to OperationState enum', function (): void {
            // Arrange & Act
            $operation = Operation::factory()->create(['state' => OperationState::Completed]);

            // Assert
            expect($operation->state)->toBeInstanceOf(OperationState::class)
                ->and($operation->state)->toBe(OperationState::Completed);
        })->group('happy-path');

        test('stores all enum states correctly', function (): void {
            // Arrange
            $states = [
                OperationState::Pending,
                OperationState::Completed,
                OperationState::Failed,
                OperationState::Skipped,
                OperationState::RolledBack,
            ];

            // Act & Assert
            foreach ($states as $state) {
                $operation = Operation::factory()->create(['state' => $state]);
                expect($operation->fresh()->state)->toBe($state);
            }
        })->group('happy-path');
    });

    describe('isSuccessful() method', function (): void {
        test('returns true for Completed state', function (): void {
            expect(OperationState::Completed->isSuccessful())->toBeTrue();
        })->group('happy-path');

        test('returns true for Skipped state', function (): void {
            expect(OperationState::Skipped->isSuccessful())->toBeTrue();
        })->group('happy-path');

        test('returns false for Failed state', function (): void {
            expect(OperationState::Failed->isSuccessful())->toBeFalse();
        })->group('happy-path');

        test('returns false for Pending state', function (): void {
            expect(OperationState::Pending->isSuccessful())->toBeFalse();
        })->group('happy-path');

        test('returns false for RolledBack state', function (): void {
            expect(OperationState::RolledBack->isSuccessful())->toBeFalse();
        })->group('happy-path');
    });

    describe('isTerminal() method', function (): void {
        test('returns false for Pending state', function (): void {
            expect(OperationState::Pending->isTerminal())->toBeFalse();
        })->group('happy-path');

        test('returns true for all non-pending states', function (): void {
            expect(OperationState::Completed->isTerminal())->toBeTrue()
                ->and(OperationState::Failed->isTerminal())->toBeTrue()
                ->and(OperationState::Skipped->isTerminal())->toBeTrue()
                ->and(OperationState::RolledBack->isTerminal())->toBeTrue();
        })->group('happy-path');
    });

    describe('isFailed() method', function (): void {
        test('returns true only for Failed state', function (): void {
            expect(OperationState::Failed->isFailed())->toBeTrue()
                ->and(OperationState::Completed->isFailed())->toBeFalse()
                ->and(OperationState::Skipped->isFailed())->toBeFalse()
                ->and(OperationState::Pending->isFailed())->toBeFalse()
                ->and(OperationState::RolledBack->isFailed())->toBeFalse();
        })->group('happy-path');
    });

    describe('isPending() method', function (): void {
        test('returns true only for Pending state', function (): void {
            expect(OperationState::Pending->isPending())->toBeTrue()
                ->and(OperationState::Completed->isPending())->toBeFalse()
                ->and(OperationState::Skipped->isPending())->toBeFalse()
                ->and(OperationState::Failed->isPending())->toBeFalse()
                ->and(OperationState::RolledBack->isPending())->toBeFalse();
        })->group('happy-path');
    });

    describe('label() method', function (): void {
        test('returns human-readable labels', function (): void {
            expect(OperationState::Pending->label())->toBe('Pending')
                ->and(OperationState::Completed->label())->toBe('Completed')
                ->and(OperationState::Failed->label())->toBe('Failed')
                ->and(OperationState::Skipped->label())->toBe('Skipped')
                ->and(OperationState::RolledBack->label())->toBe('Rolled Back');
        })->group('happy-path');
    });

    describe('color() method', function (): void {
        test('returns appropriate color codes', function (): void {
            expect(OperationState::Pending->color())->toBe('yellow')
                ->and(OperationState::Completed->color())->toBe('green')
                ->and(OperationState::Failed->color())->toBe('red')
                ->and(OperationState::Skipped->color())->toBe('blue')
                ->and(OperationState::RolledBack->color())->toBe('orange');
        })->group('happy-path');
    });

    describe('skip_reason storage', function (): void {
        test('stores skip reason when operation is skipped', function (): void {
            // Arrange & Act
            $operation = Operation::factory()->create([
                'skipped_at' => Date::now(),
                'skip_reason' => 'Resource not modified',
                'state' => OperationState::Skipped,
            ]);

            // Assert
            expect($operation->skip_reason)->toBe('Resource not modified')
                ->and($operation->state)->toBe(OperationState::Skipped);
        })->group('happy-path');

        test('skip_reason is nullable for non-skipped operations', function (): void {
            // Arrange & Act
            $operation = Operation::factory()->create([
                'completed_at' => Date::now(),
                'state' => OperationState::Completed,
            ]);

            // Assert
            expect($operation->skip_reason)->toBeNull();
        })->group('happy-path');
    });
});
