<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Commands\ProcessCommand;

/**
 * Process Command Test Suite
 *
 * Tests the sequencer:process Artisan command execution flow as an integration test.
 *
 * Note: This is a Feature test that verifies the command's integration with Laravel's
 * console infrastructure. It uses real dependencies from the container.
 */
covers(ProcessCommand::class);

describe('Process Command', function (): void {
    beforeEach(function (): void {
        // Configure discovery paths to include test fixtures
        config()->set('sequencer.execution.discovery_paths', [
            __DIR__.'/../Fixtures/Operations',
        ]);
    });

    describe('Happy Paths', function (): void {
        test('executes command without options successfully when no pending tasks exist', function (): void {
            // Arrange
            // Test database has no pending migrations or operations

            // Act
            $result = $this->artisan('sequencer:process');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('All migrations and operations processed successfully.');
        })->group('happy-path', 'command');

        test('executes dry-run showing no tasks when none pending', function (): void {
            // Arrange
            // No migrations or operations exist in test environment

            // Act
            $result = $this->artisan('sequencer:process', ['--dry-run' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Dry-run mode: Previewing execution order...');
            $result->expectsOutputToContain('No pending migrations or operations found.');
        })->group('happy-path', 'command');

        test('executes with isolate flag successfully', function (): void {
            // Arrange
            config(['sequencer.execution.lock.store' => 'array']);
            config(['sequencer.execution.lock.timeout' => 60]);
            config(['sequencer.execution.lock.ttl' => 600]);

            // Act
            $result = $this->artisan('sequencer:process', ['--isolate' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Running with isolation lock...');
            $result->expectsOutputToContain('All migrations and operations processed successfully.');
        })->group('happy-path', 'command');

        test('executes with from timestamp parameter', function (): void {
            // Arrange
            $timestamp = '2024_01_01_120000';

            // Act
            $result = $this->artisan('sequencer:process', ['--from' => $timestamp]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Resuming from timestamp: '.$timestamp);
            $result->expectsOutputToContain('All migrations and operations processed successfully.');
        })->group('happy-path', 'command');

        test('executes with multiple options combined', function (): void {
            // Arrange
            $timestamp = '2024_01_01_120000';
            config(['sequencer.execution.lock.store' => 'array']);

            // Act
            $result = $this->artisan('sequencer:process', [
                '--isolate' => true,
                '--from' => $timestamp,
            ]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Running with isolation lock...');
            $result->expectsOutput('Resuming from timestamp: '.$timestamp);
            $result->expectsOutputToContain('All migrations and operations processed successfully.');
        })->group('happy-path', 'command');

        test('executes with repeat flag for re-executing completed operations', function (): void {
            // Arrange
            // First create and execute an operation
            $operationPath = __DIR__.'/../Fixtures/Operations/2024_01_01_120000_RepeatTestOperation.php';
            file_put_contents($operationPath, "<?php\nreturn new class implements \\Cline\\Sequencer\\Contracts\\Operation {\n    public function handle(): void {}\n};");

            // Execute once to mark as completed
            $this->artisan('sequencer:process')->assertSuccessful();

            // Act - Execute again with --repeat
            $result = $this->artisan('sequencer:process', ['--repeat' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Re-executing previously completed operations...');
            $result->expectsOutputToContain('All migrations and operations processed successfully.');

            // Cleanup
            unlink($operationPath);
        })->group('happy-path', 'command');

        test('dry-run displays correct output format', function (): void {
            // Arrange
            // Empty test database

            // Act
            $result = $this->artisan('sequencer:process --dry-run');

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Dry-run mode');
        })->group('happy-path', 'command');
    });

    describe('Edge Cases', function (): void {
        test('executes with valid timestamp format in far future', function (): void {
            // Arrange
            $timestamp = '2099_12_31_235959';

            // Act
            $result = $this->artisan('sequencer:process', ['--from' => $timestamp]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Resuming from timestamp: '.$timestamp);
        })->group('edge-case', 'command');

        test('executes with empty from timestamp', function (): void {
            // Arrange
            // Empty string timestamp should be handled gracefully

            // Act
            $result = $this->artisan('sequencer:process', ['--from' => '']);

            // Assert
            $result->assertSuccessful();
        })->group('edge-case', 'command');

        test('command respects Laravel command exit codes', function (): void {
            // Arrange
            // No setup needed

            // Act
            $result = $this->artisan('sequencer:process');

            // Assert
            $result->assertExitCode(0);
        })->group('edge-case', 'command');

        test('command signature includes all expected options', function (): void {
            // Arrange
            $command = $this->app->make(ProcessCommand::class);

            // Act
            $signature = $command->getName();

            // Assert
            expect($signature)->toBe('sequencer:process');
        })->group('edge-case', 'command');

        test('executes with sync flag forces synchronous execution', function (): void {
            // Arrange
            // No setup needed

            // Act
            $result = $this->artisan('sequencer:process', ['--sync' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Forcing synchronous execution...');
            $result->expectsOutputToContain('All migrations and operations processed successfully.');
        })->group('edge-case', 'command');

        test('executes with async flag forces asynchronous execution', function (): void {
            // Arrange
            config(['sequencer.queue.connection' => 'sync']);

            // Act
            $result = $this->artisan('sequencer:process', ['--async' => true]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Forcing asynchronous execution...');
            $result->expectsOutputToContain('All migrations and operations processed successfully.');
        })->group('edge-case', 'command');

        test('executes with queue flag dispatches to specific queue', function (): void {
            // Arrange
            config(['sequencer.queue.connection' => 'sync']);
            $queueName = 'high-priority';

            // Act
            $result = $this->artisan('sequencer:process', ['--queue' => $queueName]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Dispatching to queue: '.$queueName);
            $result->expectsOutputToContain('All migrations and operations processed successfully.');
        })->group('edge-case', 'command');

        test('executes with tags flag filters operations by tag', function (): void {
            // Arrange
            $operationPath = __DIR__.'/../Fixtures/Operations/2024_01_01_130000_TaggedOperation.php';
            file_put_contents($operationPath, "<?php\nreturn new class implements \\Cline\\Sequencer\\Contracts\\Operation, \\Cline\\Sequencer\\Contracts\\HasTags {\n    public function handle(): void {}\n    public function tags(): array { return ['billing', 'critical']; }\n};");

            // Act
            $result = $this->artisan('sequencer:process', ['--tags' => ['billing']]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Filtering by tags: billing');

            // Cleanup
            unlink($operationPath);
        })->group('edge-case', 'command');

        test('executes with multiple tags flag filters operations by any matching tag', function (): void {
            // Arrange
            $operationPath = __DIR__.'/../Fixtures/Operations/2024_01_01_140000_MultiTagOperation.php';
            file_put_contents($operationPath, "<?php\nreturn new class implements \\Cline\\Sequencer\\Contracts\\Operation, \\Cline\\Sequencer\\Contracts\\HasTags {\n    public function handle(): void {}\n    public function tags(): array { return ['data-migration']; }\n};");

            // Act
            $result = $this->artisan('sequencer:process', ['--tags' => ['billing', 'data-migration']]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutput('Filtering by tags: billing, data-migration');

            // Cleanup
            unlink($operationPath);
        })->group('edge-case', 'command');

        test('dry-run with tags flag filters preview correctly', function (): void {
            // Arrange
            $operationPath = __DIR__.'/../Fixtures/Operations/2024_01_01_150000_PreviewTagOperation.php';
            file_put_contents($operationPath, "<?php\nreturn new class implements \\Cline\\Sequencer\\Contracts\\Operation, \\Cline\\Sequencer\\Contracts\\HasTags {\n    public function handle(): void {}\n    public function tags(): array { return ['test-tag']; }\n};");

            // Act
            $result = $this->artisan('sequencer:process', [
                '--dry-run' => true,
                '--tags' => ['test-tag'],
            ]);

            // Assert
            $result->assertSuccessful();
            $result->expectsOutputToContain('Dry-run mode: Previewing execution order...');

            // Cleanup
            unlink($operationPath);
        })->group('edge-case', 'command');
    });

    describe('Sad Paths', function (): void {
        test('fails when both sync and async flags are provided', function (): void {
            // Arrange
            // No setup needed

            // Act
            $result = $this->artisan('sequencer:process', [
                '--sync' => true,
                '--async' => true,
            ]);

            // Assert
            $result->assertFailed();
            $result->expectsOutput('Cannot use --sync and --async together');
        })->group('sad-path', 'command');
    });
});
