<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Commands\ProcessScheduledCommand;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Process Scheduled Command Test Suite
 *
 * Tests the sequencer:scheduled Artisan command for processing scheduled operations.
 *
 * Note: This is a Feature test that verifies the command's integration with Laravel's
 * console infrastructure and the ScheduledOrchestrator service.
 */
covers(ProcessScheduledCommand::class);

describe('Process Scheduled Command', function (): void {
    beforeEach(function (): void {
        // Create temp directory for test operations
        $this->tempDir = storage_path('framework/testing/scheduled_command_'.uniqid());
        File::makeDirectory($this->tempDir, 0o755, true);

        // Configure discovery paths to use temp directory
        config()->set('sequencer.execution.discovery_paths', [
            $this->tempDir,
        ]);

        // Set a consistent "now" time for testing
        Date::setTestNow('2024-01-15 12:00:00');

        // Fake queue and events for most tests (individual tests can override)
        Queue::fake();
        Event::fake();
    });

    afterEach(function (): void {
        // Clean up temp directory
        if (property_exists($this, 'tempDir') && $this->tempDir !== null && is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        // Reset Date facade
        Date::setTestNow();
    });

    describe('Happy Paths', function (): void {
        test('succeeds when no scheduled operations are due', function (): void {
            // Arrange
            $futureOperationPath = $this->tempDir.'/2025_12_20_120000_FutureScheduledOperation.php';
            $futureContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2025-12-20 12:00:00');
    }
};
PHP;
            file_put_contents($futureOperationPath, $futureContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('No scheduled operations due for execution');
        })->group('happy-path', 'command');

        test('finds and dispatches scheduled operations that are due', function (): void {
            // Arrange
            $dueOperationPath = $this->tempDir.'/2025_11_10_120000_DueScheduledOperation.php';
            $dueContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-10 12:00:00');
    }
};
PHP;
            file_put_contents($dueOperationPath, $dueContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution')
                ->expectsOutputToContain('Scheduled operations dispatched successfully');
        })->group('happy-path', 'command');

        test('displays operation name for operations that are due', function (): void {
            // Arrange
            $dueOperationPath = $this->tempDir.'/2025_11_12_120000_DisplayTimeOperation.php';
            $dueContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-12 10:30:00');
    }
};
PHP;
            file_put_contents($dueOperationPath, $dueContent);

            // Act & Assert - Command shows operation details via twoColumnDetail
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('DisplayTimeOperation')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution');
        })->group('happy-path', 'command');

        test('respects --from option to filter by timestamp', function (): void {
            // Arrange
            // Create two operations: one before and one after the 'from' timestamp
            $beforeOperationPath = $this->tempDir.'/2025_11_05_120000_BeforeFromOperation.php';
            $beforeContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-05 12:00:00');
    }
};
PHP;
            file_put_contents($beforeOperationPath, $beforeContent);

            $afterOperationPath = $this->tempDir.'/2025_11_11_120000_AfterFromOperation.php';
            $afterContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-11 12:00:00');
    }
};
PHP;
            file_put_contents($afterOperationPath, $afterContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled', ['--from' => '2025_11_10_000000'])
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution')
                ->expectsOutputToContain('2025_11_11_120000_AfterFromOperation');
        })->group('happy-path', 'command');

        test('respects --repeat option to include completed operations', function (): void {
            // Arrange
            $completedOperationPath = $this->tempDir.'/2025_11_08_120000_CompletedOperation.php';
            $completedContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-08 12:00:00');
    }
};
PHP;
            file_put_contents($completedOperationPath, $completedContent);

            // Mark operation as completed in database (name includes .php extension)
            OperationModel::query()->create([
                'name' => '2025_11_08_120000_CompletedOperation.php',
                'type' => 'scheduled',
                'executed_at' => Date::parse('2024-01-08 12:00:00'),
                'completed_at' => Date::parse('2024-01-08 12:05:00'),
                'state' => 'completed',
            ]);

            // Act & Assert
            $this->artisan('sequencer:scheduled', ['--repeat' => true])
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution');
        })->group('happy-path', 'command');

        test('processes multiple scheduled operations that are due', function (): void {
            // Arrange
            $operation1Path = $this->tempDir.'/2025_11_09_120000_FirstDueOperation.php';
            $operation1Content = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-09 12:00:00');
    }
};
PHP;
            file_put_contents($operation1Path, $operation1Content);

            $operation2Path = $this->tempDir.'/2025_11_13_120000_SecondDueOperation.php';
            $operation2Content = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-13 12:00:00');
    }
};
PHP;
            file_put_contents($operation2Path, $operation2Content);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 2 scheduled operation(s) ready for execution')
                ->expectsOutputToContain('Scheduled operations dispatched successfully');
        })->group('happy-path', 'command');
    });

    describe('Sad Paths', function (): void {
        test('reports no scheduled operations when only non-scheduled operations exist', function (): void {
            // Arrange - Create an operation that does NOT implement Scheduled
            // The command only processes operations implementing Scheduled interface
            $nonScheduledPath = $this->tempDir.'/2025_11_14_120000_NonScheduledOperation.php';
            $nonScheduledContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {
        throw new \RuntimeException('This should not execute');
    }
};
PHP;
            file_put_contents($nonScheduledPath, $nonScheduledContent);

            // Act & Assert - Command sees no scheduled operations due
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('No scheduled operations due for execution');
        })->group('sad-path', 'command');

        test('handles empty scheduled operations gracefully', function (): void {
            // Arrange - No operations created, temp directory is empty

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('No scheduled operations due for execution');
        })->group('sad-path', 'command');
    });

    describe('Edge Cases', function (): void {
        test('skips operations not implementing Scheduled interface', function (): void {
            // Arrange
            $nonScheduledPath = $this->tempDir.'/2025_11_17_120000_NonScheduledOperation.php';
            $nonScheduledContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
            file_put_contents($nonScheduledPath, $nonScheduledContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('No scheduled operations due for execution');
        })->group('edge-case', 'command');

        test('skips operations scheduled for future', function (): void {
            // Arrange
            $futureOperationPath = $this->tempDir.'/2025_11_18_120000_FarFutureOperation.php';
            $futureContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2026-12-31 23:59:59');
    }
};
PHP;
            file_put_contents($futureOperationPath, $futureContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('No scheduled operations due for execution');
        })->group('edge-case', 'command');

        test('processes operations with executeAt exactly equal to current time', function (): void {
            // Arrange
            $exactTimeOperationPath = $this->tempDir.'/2025_11_15_120000_ExactTimeOperation.php';
            $exactTimeContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-15 12:00:00');
    }
};
PHP;
            file_put_contents($exactTimeOperationPath, $exactTimeContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution')
                ->expectsOutputToContain('Scheduled operations dispatched successfully');
        })->group('edge-case', 'command');

        test('handles mix of scheduled and non-scheduled operations correctly', function (): void {
            // Arrange
            $scheduledPath = $this->tempDir.'/2025_11_19_120000_MixedScheduledOperation.php';
            $scheduledContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-14 12:00:00');
    }
};
PHP;
            file_put_contents($scheduledPath, $scheduledContent);

            $nonScheduledPath = $this->tempDir.'/2025_11_19_130000_MixedNonScheduledOperation.php';
            $nonScheduledContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
            file_put_contents($nonScheduledPath, $nonScheduledContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution')
                ->expectsOutputToContain('2025_11_19_120000_MixedScheduledOperation');
        })->group('edge-case', 'command');

        test('works with operations scheduled in the past', function (): void {
            // Arrange
            $pastOperationPath = $this->tempDir.'/2025_11_01_000000_VeryOldOperation.php';
            $pastContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2023-01-01 00:00:00');
    }
};
PHP;
            file_put_contents($pastOperationPath, $pastContent);

            // Act & Assert
            $this->artisan('sequencer:scheduled')
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution')
                ->expectsOutputToContain('Scheduled for 2023-01-01 00:00:00');
        })->group('edge-case', 'command');

        test('combines --from and --repeat options correctly', function (): void {
            // Arrange
            $completedOperationPath = $this->tempDir.'/2025_11_06_120000_CompletedFromRepeatOperation.php';
            $completedContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation, \Cline\Sequencer\Contracts\Scheduled {
    public function handle(): void {}

    public function executeAt(): \DateTimeInterface {
        return \Illuminate\Support\Facades\Date::parse('2024-01-06 12:00:00');
    }
};
PHP;
            file_put_contents($completedOperationPath, $completedContent);

            // Mark operation as completed in database (name includes .php extension)
            OperationModel::query()->create([
                'name' => '2025_11_06_120000_CompletedFromRepeatOperation.php',
                'type' => 'scheduled',
                'executed_at' => Date::parse('2024-01-06 12:00:00'),
                'completed_at' => Date::parse('2024-01-06 12:05:00'),
                'state' => 'completed',
            ]);

            // Act & Assert - With from filter that includes this operation
            $this->artisan('sequencer:scheduled', [
                '--from' => '2025_11_05_000000',
                '--repeat' => true,
            ])
                ->assertSuccessful()
                ->expectsOutputToContain('Checking for scheduled operations...')
                ->expectsOutputToContain('Found 1 scheduled operation(s) ready for execution');
        })->group('edge-case', 'command');
    });
});
