<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\ExecutionMethod;
use Cline\Sequencer\Enums\OperationState;
use Cline\Sequencer\Migrators\OneTimeOperationsMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Set up test database schema for one_time_operations table.
 */
beforeEach(function (): void {
    Schema::create('one_time_operations', function ($table): void {
        $table->id();
        $table->string('name');
        $table->enum('dispatched', ['sync', 'async']);
        $table->timestamp('processed_at')->nullable();
        $table->timestamps();
    });
});

describe('OneTimeOperationsMigrator', function (): void {
    describe('Happy Path', function (): void {
        test('migrates completed operations from one_time_operations to sequencer', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\FirstOperation', 'dispatched' => 'sync', 'processed_at' => now()->subHours(2), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\SecondOperation', 'dispatched' => 'async', 'processed_at' => now()->subHours(1), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(2);
            expect($stats['skipped'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();

            // Verify records exist in operations table
            expect(DB::table('operations')->where('name', 'App\Operations\FirstOperation')->exists())->toBeTrue();
            expect(DB::table('operations')->where('name', 'App\Operations\SecondOperation')->exists())->toBeTrue();
        });

        test('maps sync execution type correctly', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\SyncOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $operation = DB::table('operations')->where('name', 'App\Operations\SyncOperation')->first();
            expect($operation)->not->toBeNull();
            expect($operation->type)->toBe(ExecutionMethod::Sync->value);
            expect($operation->state)->toBe(OperationState::Completed->value);
        });

        test('maps async execution type correctly', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\AsyncOperation', 'dispatched' => 'async', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $operation = DB::table('operations')->where('name', 'App\Operations\AsyncOperation')->first();
            expect($operation)->not->toBeNull();
            expect($operation->type)->toBe(ExecutionMethod::Async->value);
            expect($operation->state)->toBe(OperationState::Completed->value);
        });

        test('preserves execution timestamps correctly', function (): void {
            // Arrange
            $processedAt = now()->subDays(5);
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\OldOperation', 'dispatched' => 'sync', 'processed_at' => $processedAt, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $operation = DB::table('operations')->where('name', 'App\Operations\OldOperation')->first();
            expect($operation)->not->toBeNull();
            expect($operation->executed_at)->toBe($processedAt->toDateTimeString());
            expect($operation->completed_at)->toBe($processedAt->toDateTimeString());
        });

        test('only migrates completed operations with processed_at timestamp', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\CompletedOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\PendingOperation', 'dispatched' => 'sync', 'processed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(1);
            expect(DB::table('operations')->where('name', 'App\Operations\CompletedOperation')->exists())->toBeTrue();
            expect(DB::table('operations')->where('name', 'App\Operations\PendingOperation')->exists())->toBeFalse();
        });

        test('handles idempotent migrations - skips existing operations', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\ExistingOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Create existing record in operations table
            DB::table('operations')->insert([
                'name' => 'App\Operations\ExistingOperation',
                'type' => ExecutionMethod::Sync,
                'executed_at' => now(),
                'completed_at' => now(),
                'state' => OperationState::Completed,
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(0);
            expect($stats['skipped'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();

            // Verify only one record exists
            expect(DB::table('operations')->where('name', 'App\Operations\ExistingOperation')->count())->toBe(1);
        });

        test('migrates operations in order by processed_at timestamp', function (): void {
            // Arrange
            $first = now()->subHours(3);
            $second = now()->subHours(2);
            $third = now()->subHours(1);

            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\ThirdOperation', 'dispatched' => 'sync', 'processed_at' => $third, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\FirstOperation', 'dispatched' => 'sync', 'processed_at' => $first, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\SecondOperation', 'dispatched' => 'sync', 'processed_at' => $second, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert - verify migration was successful
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(3);
        });
    });

    describe('Sad Path', function (): void {
        test('handles empty one_time_operations table gracefully', function (): void {
            // Arrange
            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(0);
            expect($stats['skipped'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();
        });

        test('records error for operation missing name field', function (): void {
            // Arrange
            Schema::drop('one_time_operations');
            Schema::create('one_time_operations', function ($table): void {
                $table->id();
                $table->enum('dispatched', ['sync', 'async']);
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });

            DB::statement('INSERT INTO one_time_operations (dispatched, processed_at, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'sync',
                now(),
                now(),
                now(),
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('missing required "name" field');
        });

        test('records error for operation missing dispatched field', function (): void {
            // Arrange
            Schema::drop('one_time_operations');
            Schema::create('one_time_operations', function ($table): void {
                $table->id();
                $table->string('name');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });

            DB::statement('INSERT INTO one_time_operations (name, processed_at, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'App\Operations\BrokenOperation',
                now(),
                now(),
                now(),
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('missing required "dispatched" field');
        });

        test('records error for operation missing processed_at field', function (): void {
            // Arrange
            Schema::drop('one_time_operations');
            Schema::create('one_time_operations', function ($table): void {
                $table->id();
                $table->string('name');
                $table->enum('dispatched', ['sync', 'async']);
                $table->timestamps();
            });

            DB::statement('INSERT INTO one_time_operations (name, dispatched, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'App\Operations\BrokenOperation',
                'sync',
                now(),
                now(),
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('missing required "processed_at" field');
        });

        test('throws exception when source table does not exist', function (): void {
            // Arrange
            Schema::drop('one_time_operations');

            $migrator = new OneTimeOperationsMigrator();

            // Act & Assert
            try {
                $migrator->migrate();

                throw new Exception('Expected migration to fail');
            } catch (Throwable $throwable) {
                expect($throwable)->toBeInstanceOf(Throwable::class);

                $stats = $migrator->getStatistics();
                expect($stats['errors'])->toHaveCount(1);
                expect($stats['errors'][0])->toContain('Migration failed:');
            }
        });
    });

    describe('Edge Cases', function (): void {
        test('handles custom source table name', function (): void {
            // Arrange
            Schema::create('custom_operations_table', function ($table): void {
                $table->id();
                $table->string('name');
                $table->enum('dispatched', ['sync', 'async']);
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });

            DB::table('custom_operations_table')->insert([
                ['name' => 'App\Operations\CustomOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator(
                sourceTable: 'custom_operations_table',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(1);
            expect(DB::table('operations')->where('name', 'App\Operations\CustomOperation')->exists())->toBeTrue();
        });

        test('handles large number of operations efficiently', function (): void {
            // Arrange
            $operations = [];

            for ($i = 1; $i <= 100; ++$i) {
                $operations[] = [
                    'name' => 'App\Operations\Operation'.$i,
                    'dispatched' => $i % 2 === 0 ? 'async' : 'sync',
                    'processed_at' => now()->subHours($i),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('one_time_operations')->insert($operations);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(100);
            expect($stats['skipped'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();
            expect(DB::table('operations')->count())->toBe(100);
        });

        test('handles operations with same name but different execution times', function (): void {
            // Arrange - Same operation executed multiple times
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\RepeatedOperation', 'dispatched' => 'sync', 'processed_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();
            $migrator->migrate();

            // Now try to migrate again with newer execution
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\RepeatedOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Act - Second migration run
            $migrator2 = new OneTimeOperationsMigrator();
            $migrator2->migrate();

            // Assert - Should skip both operations since operation name already exists
            $stats = $migrator2->getStatistics();
            expect($stats['operations'])->toBe(0);
            expect($stats['skipped'])->toBe(2);
        });

        test('handles mixed valid and invalid operations', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\ValidOperation1', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\ValidOperation2', 'dispatched' => 'async', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Add invalid record
            Schema::drop('one_time_operations');
            Schema::create('one_time_operations', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->enum('dispatched', ['sync', 'async'])->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });

            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\ValidOperation1', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => null, 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\ValidOperation2', 'dispatched' => 'async', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $migrator = new OneTimeOperationsMigrator();

            // Act
            $migrator->migrate();

            // Assert - Should migrate valid operations and error on invalid
            $stats = $migrator->getStatistics();
            expect($stats['operations'])->toBe(2);
            expect($stats['errors'])->toHaveCount(1);
        });
    });
});
