<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('one_time_operations', function ($table): void {
        $table->id();
        $table->string('name');
        $table->enum('dispatched', ['sync', 'async']);
        $table->timestamp('processed_at')->nullable();
        $table->timestamps();
    });
});

describe('MigrateFromOneTimeOperationsCommand', function (): void {
    describe('Happy Path', function (): void {
        test('successfully migrates operations with confirmation', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\FirstOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\SecondOperation', 'dispatched' => 'async', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Act
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--force' => true])
                ->assertExitCode(0)
                ->expectsOutput('Sequencer Migration from laravel-one-time-operations')
                ->expectsOutput('Starting migration...')
                ->expectsOutput('Migration completed!')
                ->expectsOutput('✓ Successfully migrated 2 operation(s) to Sequencer');

            // Assert
            expect(DB::table('operations')->where('name', 'App\Operations\FirstOperation')->exists())->toBeTrue();
            expect(DB::table('operations')->where('name', 'App\Operations\SecondOperation')->exists())->toBeTrue();
        });

        test('displays preview with dry-run flag', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\TestOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Act
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--dry-run' => true])
                ->assertExitCode(0)
                ->expectsOutput('✓ Dry run completed - no changes made');

            // Assert - No operations migrated
            expect(DB::table('operations')->count())->toBe(0);
        });

        test('skips already-migrated operations', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\ExistingOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Pre-migrate operation
            DB::table('operations')->insert([
                'name' => 'App\Operations\ExistingOperation',
                'type' => 'sync',
                'executed_at' => now(),
                'completed_at' => now(),
                'state' => 'completed',
            ]);

            // Act
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--force' => true])
                ->assertExitCode(0);

            // Assert - Still only one record
            expect(DB::table('operations')->where('name', 'App\Operations\ExistingOperation')->count())->toBe(1);
        });

        test('accepts custom table name via option', function (): void {
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

            // Act
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--table' => 'custom_operations_table', '--force' => true])
                ->assertExitCode(0);

            // Assert
            expect(DB::table('operations')->where('name', 'App\Operations\CustomOperation')->exists())->toBeTrue();
        });

        test('displays statistics after migration', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\Operation1', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\Operation2', 'dispatched' => 'async', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Act & Assert
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--force' => true])
                ->assertExitCode(0)
                ->expectsOutputToContain('Operations migrated')
                ->expectsOutputToContain('Operations skipped (duplicates)')
                ->expectsOutputToContain('Errors');
        });
    });

    describe('Sad Path', function (): void {
        // Note: Lines 97-100 in MigrateCommand.php (non-string driver check) are not testable
        // because Laravel's console option API always returns null or string for --driver= options.
        // The is_string() check cannot be triggered in practice as the option type prevents
        // non-string values from being passed.

        test('requires driver argument', function (): void {
            // Act & Assert
            $this->artisan('sequencer:migrate')
                ->assertExitCode(1)
                ->expectsOutput('Migration driver is required.')
                ->expectsOutputToContain('Available drivers:')
                ->expectsOutputToContain('oto: TimoKoerber/laravel-one-time-operations');
        });

        test('fails gracefully with unsupported driver', function (): void {
            // Act & Assert
            $this->artisan('sequencer:migrate', ['--driver' => 'unsupported'])
                ->assertExitCode(1)
                ->expectsOutput('Unsupported migration driver: unsupported')
                ->expectsOutputToContain('Available drivers:');
        });

        test('fails gracefully when source table does not exist', function (): void {
            // Arrange - Drop the table
            Schema::drop('one_time_operations');

            // Act & Assert
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--force' => true])
                ->assertExitCode(1)
                ->expectsOutputToContain('Unable to access source table "one_time_operations"');
        });

        test('handles empty source table gracefully', function (): void {
            // Arrange - Empty table, no operations

            // Act & Assert
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--force' => true])
                ->assertExitCode(0)
                ->expectsOutput('No completed operations found in source table.');
        });

        test('only migrates completed operations with processed_at', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\CompletedOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\PendingOperation', 'dispatched' => 'sync', 'processed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Act
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--force' => true])
                ->assertExitCode(0);

            // Assert
            expect(DB::table('operations')->where('name', 'App\Operations\CompletedOperation')->exists())->toBeTrue();
            expect(DB::table('operations')->where('name', 'App\Operations\PendingOperation')->exists())->toBeFalse();
        });

        test('displays warning message when migration has errors', function (): void {
            // Arrange - Create operations with invalid data that will trigger errors during migration
            // The null 'name' field will cause the migrator to throw a RuntimeException
            // which gets caught and added to the errors array
            Schema::drop('one_time_operations');
            Schema::create('one_time_operations', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->enum('dispatched', ['sync', 'async'])->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });

            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\ValidOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => null, 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Act & Assert
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--force' => true])
                ->assertExitCode(0)
                // Lines 246-253: Error list display
                ->expectsOutputToContain('Errors encountered during migration:')
                // Lines 261-262: Warning message with error count
                ->expectsOutputToContain('Migrated 1 operation(s) with 1 error(s)')
                ->expectsOutputToContain('Review errors above and re-run migration if needed');
        });
    });

    describe('Edge Cases', function (): void {
        test('displays preview showing already migrated operations', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\ExistingOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'App\Operations\NewOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Pre-migrate one operation
            DB::table('operations')->insert([
                'name' => 'App\Operations\ExistingOperation',
                'type' => 'sync',
                'executed_at' => now(),
                'completed_at' => now(),
                'state' => 'completed',
            ]);

            // Act & Assert
            $this->artisan('sequencer:migrate', ['--driver' => 'oto', '--dry-run' => true])
                ->assertExitCode(0)
                ->expectsOutputToContain('Total operations in source')
                ->expectsOutputToContain('Already migrated (will be skipped)')
                ->expectsOutputToContain('New operations to migrate');
        });

        test('prompts for confirmation without force flag', function (): void {
            // Arrange
            DB::table('one_time_operations')->insert([
                ['name' => 'App\Operations\TestOperation', 'dispatched' => 'sync', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Act & Assert
            $this->artisan('sequencer:migrate', ['--driver' => 'oto'])
                ->expectsConfirmation('Migrate 1 operation(s) to Sequencer?', 'no')
                ->expectsOutput('Migration cancelled.')
                ->assertExitCode(0);

            // Verify nothing was migrated
            expect(DB::table('operations')->count())->toBe(0);
        });
    });
});
