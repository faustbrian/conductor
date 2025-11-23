<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\OperationState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;

uses(RefreshDatabase::class);

describe('OperationBuilder Query Scopes', function (): void {
    describe('executed() scope', function (): void {
        test('filters operations that have been executed', function (): void {
            // Arrange - all operations have executed_at by default from factory
            $op1 = Operation::factory()->create();
            $op2 = Operation::factory()->create();

            // Act
            $executed = Operation::executed()->get();

            // Assert
            expect($executed)->toHaveCount(2);
            expect($executed->pluck('id')->toArray())->toContain($op1->id, $op2->id);
        })->group('happy-path');
    });

    describe('completed() scope', function (): void {
        test('filters completed operations', function (): void {
            // Arrange
            $completed = Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);
            $pending = Operation::factory()->create(['completed_at' => null, 'state' => OperationState::Pending]);

            // Act
            $operations = Operation::completed()->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($completed->id);
        })->group('happy-path');
    });

    describe('failed() scope', function (): void {
        test('filters failed operations', function (): void {
            // Arrange
            $failed = Operation::factory()->create(['failed_at' => Date::now(), 'state' => OperationState::Failed]);
            $completed = Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);

            // Act
            $operations = Operation::failed()->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($failed->id);
        })->group('happy-path');
    });

    describe('rolledBack() scope', function (): void {
        test('filters rolled back operations', function (): void {
            // Arrange
            $rolledBack = Operation::factory()->create(['rolled_back_at' => Date::now(), 'state' => OperationState::RolledBack]);
            $completed = Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);

            // Act
            $operations = Operation::rolledBack()->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($rolledBack->id);
        })->group('happy-path');
    });

    describe('pending() scope', function (): void {
        test('filters pending operations', function (): void {
            // Arrange
            $pending = Operation::factory()->create([
                'executed_at' => Date::now(),
                'completed_at' => null,
                'failed_at' => null,
                'state' => OperationState::Pending,
            ]);
            $completed = Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);

            // Act
            $operations = Operation::pending()->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($pending->id);
        })->group('happy-path');
    });

    describe('synchronous() scope', function (): void {
        test('filters synchronous operations', function (): void {
            // Arrange
            $sync = Operation::factory()->create(['type' => 'sync']);
            $async = Operation::factory()->create(['type' => 'async']);

            // Act
            $operations = Operation::synchronous()->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($sync->id);
        })->group('happy-path');
    });

    describe('asynchronous() scope', function (): void {
        test('filters asynchronous operations', function (): void {
            // Arrange
            $sync = Operation::factory()->create(['type' => 'sync']);
            $async = Operation::factory()->create(['type' => 'async']);

            // Act
            $operations = Operation::asynchronous()->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($async->id);
        })->group('happy-path');
    });

    describe('named() scope', function (): void {
        test('filters by exact name', function (): void {
            // Arrange
            $op1 = Operation::factory()->create(['name' => 'TestOperation']);
            $op2 = Operation::factory()->create(['name' => 'OtherOperation']);

            // Act
            $operations = Operation::named('TestOperation')->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($op1->id);
        })->group('happy-path');

        test('filters by wildcard pattern', function (): void {
            // Arrange
            $op1 = Operation::factory()->create(['name' => 'TestOperation']);
            $op2 = Operation::factory()->create(['name' => 'TestAnother']);
            $op3 = Operation::factory()->create(['name' => 'OtherOperation']);

            // Act
            $operations = Operation::named('Test%')->get();

            // Assert
            expect($operations)->toHaveCount(2);
        })->group('happy-path');
    });

    describe('today() scope', function (): void {
        test('filters operations executed today', function (): void {
            // Arrange
            $today = Operation::factory()->create(['executed_at' => Date::now()]);
            $yesterday = Operation::factory()->create(['executed_at' => Date::now()->subDay()]);

            // Act
            $operations = Operation::today()->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->id)->toBe($today->id);
        })->group('happy-path');
    });

    describe('orderedByExecution() scope', function (): void {
        test('orders by execution ascending', function (): void {
            // Arrange
            $later = Operation::factory()->create(['executed_at' => Date::now()]);
            $earlier = Operation::factory()->create(['executed_at' => Date::now()->subHour()]);

            // Act
            $operations = Operation::orderedByExecution('asc')->get();

            // Assert
            expect($operations->first()->id)->toBe($earlier->id);
            expect($operations->last()->id)->toBe($later->id);
        })->group('happy-path');

        test('orders by execution descending', function (): void {
            // Arrange
            $later = Operation::factory()->create(['executed_at' => Date::now()]);
            $earlier = Operation::factory()->create(['executed_at' => Date::now()->subHour()]);

            // Act
            $operations = Operation::orderedByExecution('desc')->get();

            // Assert
            expect($operations->first()->id)->toBe($later->id);
            expect($operations->last()->id)->toBe($earlier->id);
        })->group('happy-path');
    });

    describe('latest() scope', function (): void {
        test('orders by most recent execution', function (): void {
            // Arrange
            $later = Operation::factory()->create(['executed_at' => Date::now()]);
            $earlier = Operation::factory()->create(['executed_at' => Date::now()->subHour()]);

            // Act
            $operations = Operation::query()->latest()->get();

            // Assert
            expect($operations->first()->id)->toBe($later->id);
        })->group('happy-path');
    });

    describe('oldest() scope', function (): void {
        test('orders by oldest execution', function (): void {
            // Arrange
            $later = Operation::factory()->create(['executed_at' => Date::now()]);
            $earlier = Operation::factory()->create(['executed_at' => Date::now()->subHour()]);

            // Act
            $operations = Operation::query()->oldest()->get();

            // Assert
            expect($operations->first()->id)->toBe($earlier->id);
        })->group('happy-path');
    });

    describe('executedBy() scope', function (): void {
        test('filters operations executed by specific entity', function (): void {
            // Arrange - Create a simple model to act as executor
            $executor = new class() extends Model
            {
                use HasFactory;

                public $timestamps = false;

                protected $table = 'temp_executors';

                public function getMorphClass(): string
                {
                    return 'TestExecutor';
                }

                public function getKey(): int
                {
                    return 123;
                }
            };

            Operation::factory()->create([
                'executed_by_type' => 'TestExecutor',
                'executed_by_id' => 123,
            ]);
            Operation::factory()->create([
                'executed_by_type' => 'OtherExecutor',
                'executed_by_id' => 456,
            ]);

            // Act
            $operations = Operation::query()->executedBy($executor)->get();

            // Assert
            expect($operations)->toHaveCount(1);
            expect($operations->first()->executed_by_type)->toBe('TestExecutor');
            expect($operations->first()->executed_by_id)->toBe(123);
        })->group('happy-path');
    });

    describe('between() scope', function (): void {
        test('returns operations within date range', function (): void {
            // Arrange
            $start = Date::now()->subDays(7);
            $end = Date::now();

            $inRange = Operation::factory()->create(['executed_at' => Date::now()->subDays(3)]);
            $outOfRange = Operation::factory()->create(['executed_at' => Date::now()->subDays(10)]);

            // Act
            $operations = Operation::between($start, $end)->get();

            // Assert
            expect($operations)->toHaveCount(1)
                ->and($operations->first()->id)->toBe($inRange->id);
        })->group('happy-path');
    });

    describe('withErrors() scope', function (): void {
        test('returns only operations with errors', function (): void {
            // Arrange
            $operationWithError = Operation::factory()->create(['state' => OperationState::Failed]);
            OperationError::factory()->create(['operation_id' => $operationWithError->id]);
            Operation::factory()->create(['state' => OperationState::Completed]);

            // Act
            $operations = Operation::withErrors()->get();

            // Assert
            expect($operations)->toHaveCount(1);
        })->group('happy-path');
    });

    describe('withoutErrors() scope', function (): void {
        test('returns only operations without errors', function (): void {
            // Arrange
            $operationWithError = Operation::factory()->create(['state' => OperationState::Failed]);
            OperationError::factory()->create(['operation_id' => $operationWithError->id]);
            Operation::factory()->create(['state' => OperationState::Completed]);

            // Act
            $operations = Operation::withoutErrors()->get();

            // Assert
            expect($operations)->toHaveCount(1);
        })->group('happy-path');
    });

    describe('skipped() scope', function (): void {
        test('returns only skipped operations', function (): void {
            // Arrange
            Operation::factory()->create(['skipped_at' => Date::now(), 'state' => OperationState::Skipped]);
            Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);
            Operation::factory()->create(['failed_at' => Date::now(), 'state' => OperationState::Failed]);

            // Act
            $skipped = Operation::skipped()->get();

            // Assert
            expect($skipped)->toHaveCount(1)
                ->and($skipped->first()->skipped_at)->not->toBeNull();
        })->group('happy-path');

        test('returns empty collection when no operations skipped', function (): void {
            // Arrange
            Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);
            Operation::factory()->create(['failed_at' => Date::now(), 'state' => OperationState::Failed]);

            // Act
            $skipped = Operation::skipped()->get();

            // Assert
            expect($skipped)->toHaveCount(0);
        })->group('edge-case');
    });

    describe('successful() scope with skipped operations', function (): void {
        test('includes both completed and skipped operations', function (): void {
            // Arrange
            Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);
            Operation::factory()->create(['skipped_at' => Date::now(), 'state' => OperationState::Skipped]);
            Operation::factory()->create(['failed_at' => Date::now(), 'state' => OperationState::Failed]);
            Operation::factory()->create(['rolled_back_at' => Date::now(), 'state' => OperationState::RolledBack]);

            // Act
            $successful = Operation::successful()->get();

            // Assert
            expect($successful)->toHaveCount(2);
        })->group('happy-path');

        test('excludes failed operations', function (): void {
            // Arrange
            Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);
            Operation::factory()->create(['failed_at' => Date::now(), 'state' => OperationState::Failed]);

            // Act
            $successful = Operation::successful()->get();

            // Assert
            expect($successful)->toHaveCount(1);
        })->group('happy-path');

        test('excludes rolled back operations', function (): void {
            // Arrange
            Operation::factory()->create([
                'completed_at' => Date::now(),
                'rolled_back_at' => Date::now(),
                'state' => OperationState::RolledBack,
            ]);
            Operation::factory()->create(['completed_at' => Date::now(), 'state' => OperationState::Completed]);

            // Act
            $successful = Operation::successful()->get();

            // Assert
            expect($successful)->toHaveCount(1)
                ->and($successful->first()->rolled_back_at)->toBeNull();
        })->group('happy-path');
    });
});
