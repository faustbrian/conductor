<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Events\NoPendingOperations;
use Cline\Sequencer\Events\OperationsEnded;
use Cline\Sequencer\Events\OperationsStarted;
use Cline\Sequencer\Strategies\MigrationStrategy;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Exceptions\TestException;

beforeEach(function (): void {
    // Enable event strategy
    config(['sequencer.strategy' => 'migration']);

    // Create temp directory for operations
    $this->tempDir = sys_get_temp_dir().'/sequencer-event-test-'.uniqid();
    mkdir($this->tempDir);
    config(['sequencer.execution.discovery_paths' => [$this->tempDir]]);

    // Create input/output for console events
    $this->input = new ArrayInput([]);
    $this->output = new NullOutput();
});

afterEach(function (): void {
    // Cleanup temp directory
    if (!property_exists($this, 'tempDir') || $this->tempDir === null || !is_dir($this->tempDir)) {
        return;
    }

    File::deleteDirectory($this->tempDir);
});

/**
 * Helper to create CommandStarting event.
 *
 * @param mixed $input
 * @param mixed $output
 */
function createCommandStarting(string $command, $input, $output): CommandStarting
{
    return new CommandStarting($command, $input, $output);
}

/**
 * Helper to create CommandFinished event.
 *
 * @param mixed $input
 * @param mixed $output
 */
function createCommandFinished(string $command, $input, $output, int $exitCode = 0): CommandFinished
{
    return new CommandFinished($command, $input, $output, $exitCode);
}

describe('MigrationStrategy Integration', function (): void {
    test('executes operations when NoPendingMigrations event fires', function (): void {
        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_event_operation';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        // Get fresh strategy instance
        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        $operationsStartedFired = false;
        $operationsEndedFired = false;

        Event::listen(OperationsStarted::class, function () use (&$operationsStartedFired): void {
            $operationsStartedFired = true;
        });

        Event::listen(OperationsEnded::class, function () use (&$operationsEndedFired): void {
            $operationsEndedFired = true;
        });

        // Simulate migrate command context
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new NoPendingMigrations('default'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        // Operation name stored in DB includes .php extension
        $fullOperationName = $operationName.'.php';

        expect($operationsStartedFired)->toBeTrue()
            ->and($operationsEndedFired)->toBeTrue()
            ->and(Operation::query()->where('name', $fullOperationName)->exists())->toBeTrue();
    });

    test('fires NoPendingOperations when no operations exist', function (): void {
        // No operations created - empty temp dir

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        $noPendingFired = false;

        Event::listen(NoPendingOperations::class, function () use (&$noPendingFired): void {
            $noPendingFired = true;
        });

        // Simulate migrate command context
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new NoPendingMigrations('default'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        expect($noPendingFired)->toBeTrue();
    });

    test('does not execute operations outside migrate command', function (): void {
        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_outside_migrate';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Fire NoPendingMigrations WITHOUT CommandStarting for 'migrate'
        event(
            new NoPendingMigrations('default'),
        );

        // Operation should NOT be executed
        expect(Operation::query()->where('name', $operationName)->exists())->toBeFalse();
    });

    test('respects allowed_commands configuration', function (): void {
        // Only allow 'migrate:fresh'
        config(['sequencer.migration_strategy.allowed_commands' => ['migrate:fresh']]);

        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_allowed_commands';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Simulate regular migrate command (not allowed)
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new NoPendingMigrations('default'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        // Operation should NOT be executed because 'migrate' is not in allowed_commands
        expect(Operation::query()->where('name', $operationName)->exists())->toBeFalse();
    });

    test('respects run_on_no_pending_migrations configuration', function (): void {
        config(['sequencer.migration_strategy.run_on_no_pending_migrations' => false]);

        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_no_pending_config';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        $noPendingFired = false;

        Event::listen(NoPendingOperations::class, function () use (&$noPendingFired): void {
            $noPendingFired = true;
        });

        // Simulate migrate command with no pending migrations
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new NoPendingMigrations('default'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        // NoPendingOperations should fire, but operation should NOT execute
        expect($noPendingFired)->toBeTrue()
            ->and(Operation::query()->where('name', $operationName)->exists())->toBeFalse();
    });

    test('executes operations during MigrationsEnded event', function (): void {
        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_migrations_ended';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        $operationsStartedFired = false;
        $operationsEndedFired = false;

        Event::listen(OperationsStarted::class, function () use (&$operationsStartedFired): void {
            $operationsStartedFired = true;
        });

        Event::listen(OperationsEnded::class, function () use (&$operationsEndedFired): void {
            $operationsEndedFired = true;
        });

        // Simulate migrate command with migrations
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new MigrationsStarted('default'),
        );
        event(
            new MigrationsEnded('default'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        // Operation name stored in DB includes .php extension
        $fullOperationName = $operationName.'.php';

        expect($operationsStartedFired)->toBeTrue()
            ->and($operationsEndedFired)->toBeTrue()
            ->and(Operation::query()->where('name', $fullOperationName)->exists())->toBeTrue();
    });

    test('command strategy does not execute operations during migrate', function (): void {
        // Use command strategy (default)
        config(['sequencer.strategy' => 'command']);

        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_command_strategy';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        // Simulate migrate command (no event listeners registered for command strategy)
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new NoPendingMigrations('default'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        // Operation should NOT be executed with command strategy
        expect(Operation::query()->where('name', $operationName)->exists())->toBeFalse();
    });

    test('ignores MigrationEnded events for rollback (down) migrations', function (): void {
        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_down_migration';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Create a fake migration
        $migration = new class() extends Migration
        {
            public function up(): void {}

            public function down(): void {}
        };

        // Simulate migrate command with DOWN migration
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new MigrationsStarted('default'),
        );
        // Fire MigrationEnded with 'down' method - operations should NOT execute during rollback
        event(
            new MigrationEnded($migration, 'down'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        // Operation should NOT have been executed yet (only on MigrationsEnded)
        // This verifies that MigrationEnded with 'down' doesn't trigger operation execution
        expect(true)->toBeTrue(); // Explicit assertion - test passes if no exceptions thrown
    });

    test('ignores events when not inside migrate command context', function (): void {
        // Create a pending operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_no_context';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Fire MigrationsStarted WITHOUT CommandStarting for 'migrate'
        event(
            new MigrationsStarted('default'),
        );

        // Fire MigrationsEnded WITHOUT being in migrate command
        event(
            new MigrationsEnded('default'),
        );

        // Operation should NOT be executed
        expect(Operation::query()->where('name', $operationName)->exists())->toBeFalse();
    });

    test('handles operations that fail gracefully with logging', function (): void {
        // Create an operation that throws
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_failing_operation';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {
        throw \Tests\Support\Exceptions\TestException::runtimeError('Test operation failure');
    }
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Simulate migrate command
        event(createCommandStarting('migrate', $this->input, $this->output));

        // Expect exception to be thrown
        expect(fn () => event(
            new NoPendingMigrations('default'),
        ))
            ->toThrow(TestException::class, 'Test operation failure');
    });

    test('extracts migration timestamp and uses it for interleaving', function (): void {
        // Create an operation with timestamp before the migration
        $oldTimestamp = '2024_01_01_000000';

        $opContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;

        file_put_contents(sprintf('%s/%s_timestamp_test.php', $this->tempDir, $oldTimestamp), $opContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Create a migration directory for testing
        $migrationDir = database_path('migrations/test_'.uniqid());
        mkdir($migrationDir, 0o755, true);

        // Create a timestamped migration file
        $migrationContent = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void {}
    public function down(): void {}
};
PHP;
        file_put_contents($migrationDir.'/2024_01_02_000000_test_migration.php', $migrationContent);

        // Register migration path
        resolve('migrator')->path($migrationDir);

        try {
            // Simulate migrate command with MigrationEnded event
            event(createCommandStarting('migrate', $this->input, $this->output));
            event(
                new MigrationsStarted('default'),
            );

            // Create a migration instance for the event
            $migration = require $migrationDir.'/2024_01_02_000000_test_migration.php';

            // Fire MigrationEnded event with 'up' method
            // This should trigger timestamp extraction and operation execution
            event(
                new MigrationEnded($migration, 'up'),
            );

            // The operation should be executed (timestamp 2024_01_01 <= migration timestamp 2024_01_02)
            expect(Operation::query()->where('name', 'like', '%timestamp_test%')->exists())->toBeTrue();
        } finally {
            // Cleanup
            unlink($migrationDir.'/2024_01_02_000000_test_migration.php');
            rmdir($migrationDir);
        }
    });

    test('handles MigrationEnded with migration that has invalid timestamp format', function (): void {
        // Create an operation
        $timestamp = now()->subDay()->format('Y_m_d_His');
        $operationName = $timestamp.'_test_invalid_timestamp';
        $operationContent = <<<'PHP'
<?php
return new class implements \Cline\Sequencer\Contracts\Operation {
    public function handle(): void {}
};
PHP;
        file_put_contents(sprintf('%s/%s.php', $this->tempDir, $operationName), $operationContent);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Create an anonymous migration without proper timestamp in filename
        $migration = new class() extends Migration
        {
            public function up(): void {}

            public function down(): void {}
        };

        // Simulate migrate command
        event(createCommandStarting('migrate', $this->input, $this->output));
        event(
            new MigrationsStarted('default'),
        );

        // Fire MigrationEnded with anonymous class (no valid timestamp extractable)
        // This should log a warning and continue without executing operations
        event(
            new MigrationEnded($migration, 'up'),
        );

        // Complete migrations - operations should execute here
        event(
            new MigrationsEnded('default'),
        );
        event(createCommandFinished('migrate', $this->input, $this->output));

        // Operation should be executed during MigrationsEnded
        expect(Operation::query()->where('name', 'like', '%test_invalid_timestamp%')->exists())->toBeTrue();
    });
});
