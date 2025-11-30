<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Commands\StatusCommand;
use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;

/**
 * Status Command Test Suite
 *
 * Tests the sequencer:status Artisan command execution flow as an integration test.
 *
 * Note: This is a Feature test that verifies the command's integration with Laravel's
 * console infrastructure. It uses real dependencies from the container.
 */
covers(StatusCommand::class);

describe('Status Command', function (): void {
    beforeEach(function (): void {
        // Configure discovery paths to include test fixtures
        config()->set('sequencer.execution.discovery_paths', [
            __DIR__.'/../Fixtures/Operations',
        ]);
    });

    describe('Happy Paths', function (): void {
        test('displays no pending items when none exist', function (): void {
            // Arrange
            // Mark all discovered timestamped operations as completed so they don't appear as pending
            $operationFiles = glob(__DIR__.'/../Fixtures/Operations/[0-9][0-9][0-9][0-9]_*.php');

            foreach ($operationFiles as $file) {
                Operation::factory()->create([
                    'name' => basename($file),
                    'executed_at' => now()->subMinutes(10),
                    'completed_at' => now()->subMinutes(5),
                ]);
            }

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('No pending migrations found.');
            $result->expectsOutputToContain('No pending operations found.');
        })->group('happy-path', 'command');

        test('displays pending operations when they exist', function (): void {
            // Arrange
            // Discovery will find operations in fixtures that haven't been executed

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Pending Operations');
        })->group('happy-path', 'command');

        test('displays pending operations table with timestamp and name columns', function (): void {
            // Arrange
            // Configure discovery path to include pending operations fixtures
            $this->app['config']->set('sequencer.execution.discovery_paths', [
                __DIR__.'/../Fixtures/PendingOperations',
            ]);

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Pending Operations');
            $result->expectsOutputToContain('PendingTestOperation.php');
            $result->expectsOutputToContain('AnotherPendingOperation.php');
            $result->expectsOutputToContain('Total: 2 pending operation(s)');
        })->group('happy-path', 'command');

        test('displays pending migrations table with timestamp and name columns', function (): void {
            // Arrange
            // Use app helper to get migrator and add migration path
            app('migrator')->path(__DIR__.'/../Fixtures/Migrations');

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Pending Migrations');
            $result->expectsOutputToContain('create_test_table');
            $result->expectsOutputToContain('add_column_to_test_table');
            $result->expectsOutputToContain('Total: 2 pending migration(s)');
        })->group('happy-path', 'command');

        test('displays completed operations', function (): void {
            // Arrange
            Operation::factory()->create([
                'name' => '2024_01_01_120000_test_operation.php',
                'type' => 'sync',
                'executed_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
                'failed_at' => null,
            ]);

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Completed Operations');
            $result->expectsOutputToContain('2024_01_01_120000_test_operation.php');
        })->group('happy-path', 'command');

        test('displays failed operations with error details', function (): void {
            // Arrange
            $operation = Operation::factory()->create([
                'name' => '2024_01_01_120000_failed_operation.php',
                'type' => 'sync',
                'executed_at' => now()->subHour(),
                'completed_at' => null,
                'failed_at' => now()->subMinutes(30),
            ]);

            OperationError::factory()->create([
                'operation_id' => $operation->id,
                'exception' => 'RuntimeException',
                'message' => 'Something went wrong',
                'trace' => 'Stack trace here...',
                'context' => ['key' => 'value'],
                'created_at' => now()->subMinutes(30),
            ]);

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert - check table rendering includes the data
            $result->assertSuccessful();
            $result->expectsOutputToContain('Failed Operations');
            $result->expectsOutputToContain('2024_01_01_120000_failed_operation.php');
            $result->expectsOutputToContain('RuntimeException');
        })->group('happy-path', 'command');

        test('filters to show only pending items', function (): void {
            // Arrange
            Operation::factory()->create([
                'name' => '2024_01_01_120000_completed.php',
                'executed_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
            ]);

            // Act
            $result = $this->artisan('sequencer:status', ['--pending' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Pending Migrations');
            $result->expectsOutputToContain('Pending Operations');
            $result->doesntExpectOutputToContain('Completed Operations');
            $result->doesntExpectOutputToContain('Failed Operations');
        })->group('happy-path', 'command');

        test('filters to show only completed items', function (): void {
            // Arrange
            Operation::factory()->create([
                'name' => '2024_01_01_120000_completed.php',
                'executed_at' => now()->subHour(),
                'completed_at' => now()->subMinutes(30),
            ]);

            // Act
            $result = $this->artisan('sequencer:status', ['--completed' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Completed Operations');
            $result->doesntExpectOutputToContain('Pending Migrations');
            $result->doesntExpectOutputToContain('Pending Operations');
        })->group('happy-path', 'command');

        test('filters to show only failed items', function (): void {
            // Arrange
            $operation = Operation::factory()->create([
                'name' => '2024_01_01_120000_failed.php',
                'executed_at' => now()->subHour(),
                'failed_at' => now()->subMinutes(30),
            ]);

            OperationError::factory()->create([
                'operation_id' => $operation->id,
                'exception' => 'RuntimeException',
                'message' => 'Failed',
            ]);

            // Act
            $result = $this->artisan('sequencer:status', ['--failed' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Failed Operations');
            $result->doesntExpectOutputToContain('Pending Migrations');
            $result->doesntExpectOutputToContain('Completed Operations');
        })->group('happy-path', 'command');

        test('displays stack trace in verbose mode for failed operations', function (): void {
            // Arrange
            $operation = Operation::factory()->create([
                'name' => '2024_01_01_120000_failed.php',
                'failed_at' => now(),
            ]);

            OperationError::factory()->create([
                'operation_id' => $operation->id,
                'exception' => 'RuntimeException',
                'message' => 'Error',
                'trace' => "Stack trace line 1\nStack trace line 2",
            ]);

            // Act
            $result = $this->artisan('sequencer:status', ['-v' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Stack Trace:');
            $result->expectsOutputToContain('Stack trace line 1');
        })->group('happy-path', 'command');
    });

    describe('Edge Cases', function (): void {
        test('handles operations with only executed_at timestamp', function (): void {
            // Arrange
            Operation::factory()->create([
                'name' => '2024_01_01_120000_test.php',
                'executed_at' => now(),
                'completed_at' => null,
                'failed_at' => null,
            ]);

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
        })->group('edge-case', 'command');

        test('displays multiple failed operations correctly', function (): void {
            // Arrange
            $op1 = Operation::factory()->create([
                'name' => '2024_01_01_120000_failed1.php',
                'failed_at' => now()->subHour(),
            ]);

            $op2 = Operation::factory()->create([
                'name' => '2024_01_01_130000_failed2.php',
                'failed_at' => now()->subMinutes(30),
            ]);

            OperationError::factory()->create(['operation_id' => $op1->id]);
            OperationError::factory()->create(['operation_id' => $op2->id]);

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Total: 2 failed operation(s)');
        })->group('edge-case', 'command');

        test('handles operation with multiple errors', function (): void {
            // Arrange
            $operation = Operation::factory()->create([
                'name' => '2024_01_01_120000_multi_error.php',
                'failed_at' => now(),
            ]);

            OperationError::factory()->create([
                'operation_id' => $operation->id,
                'exception' => 'FirstException',
                'message' => 'First error',
            ]);

            OperationError::factory()->create([
                'operation_id' => $operation->id,
                'exception' => 'SecondException',
                'message' => 'Second error',
            ]);

            // Act
            $result = $this->artisan('sequencer:status');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('FirstException');
            $result->expectsOutputToContain('SecondException');
        })->group('edge-case', 'command');
    });
});
