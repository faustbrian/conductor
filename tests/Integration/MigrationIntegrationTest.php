<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\SequentialOrchestrator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Migration Integration Tests.
 *
 * These tests verify migration discovery, preview, and execution paths
 * in SequentialOrchestrator WITHOUT using RefreshDatabase trait.
 *
 * Strategy: Create migrations in a separate directory that Laravel's
 * migrator doesn't know about initially, then configure discovery to find them.
 *
 * Coverage targets:
 * - Line 108: Migration name extraction in preview ('migration' => $task['data']['name'])
 * - Line 163: Migration execution routing ('migration' => $this->executeMigration($task))
 * - Lines 222-223: Adding migrations to tasks array
 * - Lines 253-256: Artisan::call migration execution
 */
describe('Migration Integration Tests', function (): void {
    beforeEach(function (): void {
        // Create isolated temp directory for migrations
        $this->migrationDir = storage_path('framework/testing/sequencer_migrations_'.uniqid());
        File::makeDirectory($this->migrationDir, 0o755, true);

        // Register migration path with Laravel's Migrator (not just config)
        // This ensures $migrator->paths() includes our custom directory
        resolve('migrator')->path($this->migrationDir);

        // Create temp directory for operations (for tests that use both migrations and operations)
        $this->tempDir = storage_path('framework/testing/operations_'.uniqid());
        File::makeDirectory($this->tempDir, 0o755, true);
        Config::set('sequencer.execution.discovery_paths', [$this->tempDir]);
    });

    afterEach(function (): void {
        // Drop any test tables created
        Schema::dropIfExists('integration_test_users');
        Schema::dropIfExists('integration_test_posts');
        Schema::dropIfExists('integration_test_comments');

        // Clean up migration records from Laravel's migrations table
        DB::table('migrations')->where('migration', 'like', '%integration_test_%')->delete();

        // Remove temp directories
        if (property_exists($this, 'migrationDir') && $this->migrationDir !== null && File::isDirectory($this->migrationDir)) {
            File::deleteDirectory($this->migrationDir);
        }

        if (property_exists($this, 'tempDir') && $this->tempDir !== null && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
    });

    test('preview includes pending migrations in dry-run mode - Line 108', function (): void {
        // Arrange: Create a migration file that hasn't been run yet
        $timestamp = '2099_01_01_000000'; // Future date to avoid conflicts
        $migrationName = $timestamp.'_create_integration_test_users_table';
        $migrationFile = $migrationName.'.php';

        $migrationContent = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('integration_test_users');
    }
};
PHP;

        File::put($this->migrationDir.'/'.$migrationFile, $migrationContent);

        // Act: Run dry-run preview
        $orchestrator = resolve(SequentialOrchestrator::class);
        $preview = $orchestrator->process(isolate: false, dryRun: true);

        // Assert: Migration appears in preview with name extracted (Line 108)
        expect($preview)->toBeArray()
            ->and($preview)->not->toBeEmpty();

        $migrationTask = collect($preview)->firstWhere('type', 'migration');
        expect($migrationTask)->not->toBeNull()
            ->and($migrationTask['type'])->toBe('migration')
            ->and($migrationTask['timestamp'])->toBe($timestamp)
            ->and($migrationTask['name'])->toBe($migrationName); // Line 108: 'migration' => $task['data']['name']
    })->group('integration', 'migration-preview');

    test('executes pending migrations during orchestration - Lines 163, 222-223, 253-256', function (): void {
        // Arrange: Create a migration file
        $timestamp = '2099_02_01_000000';
        $migrationName = $timestamp.'_create_integration_test_posts_table';
        $migrationFile = $migrationName.'.php';

        $migrationContent = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_test_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('integration_test_posts');
    }
};
PHP;

        File::put($this->migrationDir.'/'.$migrationFile, $migrationContent);

        // Act: Execute orchestrator (will discover and run migration)
        $orchestrator = resolve(SequentialOrchestrator::class);
        $orchestrator->process();

        // Assert: Migration was discovered, added to tasks, and executed
        // Lines 222-223: migration added to tasks array in discoverPendingTasks()
        // Line 163: 'migration' => $this->executeMigration($task) match arm executed
        // Lines 253-256: Artisan::call('migrate', ...) executed the migration
        expect(Schema::hasTable('integration_test_posts'))->toBeTrue('Migration should have created the table')
            ->and(DB::table('migrations')->where('migration', $migrationName)->exists())->toBeTrue('Migration should be recorded');
    })->group('integration', 'migration-execution');

    test('processes migrations and operations in chronological order', function (): void {
        // Arrange: Create migration THEN operation with timestamps ensuring migration runs first
        $baseTime = '2099_03_01_1200';

        // Migration first (00 suffix for seconds)
        $migrationTimestamp = $baseTime.'00';
        $migrationFile = $migrationTimestamp.'_create_integration_test_comments_table.php';

        $migrationContent = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_test_comments', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('integration_test_comments');
    }
};
PHP;

        File::put($this->migrationDir.'/'.$migrationFile, $migrationContent);

        // Operation second (01 suffix for seconds) - depends on table existing
        $operationTimestamp = $baseTime.'01';
        $operationFile = $operationTimestamp.'_seed_integration_comments.php';

        $operationContent = <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
use Illuminate\Support\Facades\DB;

return new class() implements Operation {
    public function handle(): void {
        // This will fail if migration didn't run first
        DB::table('integration_test_comments')->insert([
            'body' => 'First comment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
PHP;

        File::put($this->tempDir.'/'.$operationFile, $operationContent);

        // Act: Execute orchestrator
        $orchestrator = resolve(SequentialOrchestrator::class);
        $orchestrator->process();

        // Assert: Migration ran first, then operation succeeded
        expect(Schema::hasTable('integration_test_comments'))->toBeTrue('Migration created table')
            ->and(DB::table('integration_test_comments')->count())->toBe(1, 'Operation inserted data')
            ->and(DB::table('integration_test_comments')->first()->body)->toBe('First comment');
    })->group('integration', 'chronological-order');

    test('dry-run shows both migrations and operations in correct order', function (): void {
        // Arrange: Create migration and operation
        $baseTime = '2099_04_01_1500';

        $migrationFile = $baseTime.'00_create_test_table.php';
        File::put(
            $this->migrationDir.'/'.$migrationFile,
            <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_test_users', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP
        );

        $operationFile = $baseTime.'01_test_operation.php';
        File::put(
            $this->tempDir.'/'.$operationFile,
            <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;

return new class() implements Operation {
    public function handle(): void {}
};
PHP
        );

        // Act: Dry-run
        $orchestrator = resolve(SequentialOrchestrator::class);
        $preview = $orchestrator->process(isolate: false, dryRun: true);

        // Assert: Both appear in chronological order
        $migrationName = $baseTime.'00_create_test_table';
        expect($preview)->toHaveCount(2)
            ->and($preview[0]['type'])->toBe('migration')
            ->and($preview[0]['name'])->toBe($migrationName) // Line 108
            ->and($preview[1]['type'])->toBe('operation');
    })->group('integration', 'dry-run-order');
});
