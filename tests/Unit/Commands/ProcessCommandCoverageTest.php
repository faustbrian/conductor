<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Commands\ProcessCommand;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * ProcessCommand Coverage Test Suite
 *
 * These tests specifically target uncovered lines in ProcessCommand to achieve 100% coverage.
 * They use integration testing with controlled scenarios to trigger exception handling and
 * dry-run table display logic.
 */
covers(ProcessCommand::class);

uses(RefreshDatabase::class);

describe('ProcessCommand Coverage Tests', function (): void {
    describe('Exception Handling - Lines 76-84', function (): void {
        test('displays error message when operation throws exception during processing', function (): void {
            // Arrange
            $uniqueId = uniqid();
            $tempDir = storage_path('framework/testing/operations_coverage_'.$uniqueId);
            mkdir($tempDir, 0o755, true);

            $operationContent = <<<'PHP'
<?php
namespace App\Operations;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\CoverageOperationException;
return new class implements Operation {
    public function handle(): void {
        throw CoverageOperationException::create();
    }
};
PHP;
            file_put_contents($tempDir.'/2099_01_01_120000_FailingTestOperation'.$uniqueId.'.php', $operationContent);

            config()->set('sequencer.execution.discovery_paths', [$tempDir]);

            // Act
            $exitCode = Artisan::call('sequencer:process', ['--from' => '2099_01_01_000000']);

            // Assert
            expect($exitCode)->toBe(1);
            expect(Artisan::output())->toContain('FAILED');

            // Cleanup
            array_map(unlink(...), glob($tempDir.'/*'));
            rmdir($tempDir);
        })->group('sad-path', 'exception-handling');

        test('displays stack trace in verbose mode when operation fails - Lines 80-82', function (): void {
            // Arrange
            $uniqueId = uniqid();
            $tempDir = storage_path('framework/testing/operations_verbose_'.$uniqueId);
            mkdir($tempDir, 0o755, true);

            $operationContent = <<<'PHP'
<?php
namespace App\Operations;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\VerboseOperationException;
return new class implements Operation {
    public function handle(): void {
        throw VerboseOperationException::create();
    }
};
PHP;
            file_put_contents($tempDir.'/2099_02_01_120000_VerboseFailingOperation'.$uniqueId.'.php', $operationContent);

            config()->set('sequencer.execution.discovery_paths', [$tempDir]);

            // Act - Run with verbose flag
            $exitCodeVerbose = Artisan::call('sequencer:process', [
                '--from' => '2099_02_01_000000',
                '-v' => true,
            ]);

            // Assert
            expect($exitCodeVerbose)->toBe(1);
            expect(Artisan::output())->toContain('Verbose exception test');

            // Cleanup
            array_map(unlink(...), glob($tempDir.'/*'));
            rmdir($tempDir);
        })->group('sad-path', 'verbose-output');

        test('returns failure exit code (FAILURE constant) when exception occurs', function (): void {
            // Arrange
            $uniqueId = uniqid();
            $tempDir = storage_path('framework/testing/operations_exitcode_'.$uniqueId);
            mkdir($tempDir, 0o755, true);

            $operationContent = <<<'PHP'
<?php
namespace App\Operations;
use Cline\Sequencer\Contracts\Operation;
use Tests\Exceptions\TestExecutionException;
return new class implements Operation {
    public function handle(): void {
        throw TestExecutionException::exitCodeTest();
    }
};
PHP;
            file_put_contents($tempDir.'/2099_03_01_120000_ExitCodeTestOperation'.$uniqueId.'.php', $operationContent);

            config()->set('sequencer.execution.discovery_paths', [$tempDir]);

            // Act
            $exitCode = Artisan::call('sequencer:process', ['--from' => '2099_03_01_000000']);

            // Assert
            expect($exitCode)->toBe(Command::FAILURE)
                ->and($exitCode)->toBe(1);

            // Cleanup
            array_map(unlink(...), glob($tempDir.'/*'));
            rmdir($tempDir);
        })->group('sad-path', 'exception-handling');
    });

    describe('Dry-Run Table Display - Lines 104-111', function (): void {
        test('displays table with task details when dry-run finds pending operations', function (): void {
            // Arrange
            $uniqueId = uniqid();
            $tempDir = storage_path('framework/testing/operations_dryrun_'.$uniqueId);
            mkdir($tempDir, 0o755, true);

            $operation1 = <<<'PHP'
<?php
namespace App\Operations;
use Cline\Sequencer\Contracts\Operation;
return new class implements Operation {
    public function handle(): void {}
};
PHP;

            $operation2 = <<<'PHP'
<?php
namespace App\Operations;
use Cline\Sequencer\Contracts\Operation;
return new class implements Operation {
    public function handle(): void {}
};
PHP;

            file_put_contents($tempDir.'/2099_04_01_120000_DryRunOperation1'.$uniqueId.'.php', $operation1);
            file_put_contents($tempDir.'/2099_04_02_120000_DryRunOperation2'.$uniqueId.'.php', $operation2);

            config()->set('sequencer.execution.discovery_paths', [$tempDir]);

            // Act
            $exitCode = Artisan::call('sequencer:process', [
                '--dry-run' => true,
                '--from' => '2099_04_01_000000',
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $output = Artisan::output();
            expect($output)->toContain('Dry-run mode: Previewing execution order...');
            expect($output)->toContain('Found');
            expect($output)->toContain('pending task');
            // Table should be displayed with Type, Timestamp, Name columns
            expect($output)->toContain('Operation'); // Type column value (capitalized)

            // Cleanup
            array_map(unlink(...), glob($tempDir.'/*'));
            rmdir($tempDir);
        })->group('happy-path', 'dry-run');

        test('dry-run table displays capitalized operation types - Line 107', function (): void {
            // Arrange
            $uniqueId = uniqid();
            $tempDir = storage_path('framework/testing/operations_capitalize_'.$uniqueId);
            mkdir($tempDir, 0o755, true);

            $operation = <<<'PHP'
<?php
namespace App\Operations;
use Cline\Sequencer\Contracts\Operation;
return new class implements Operation {
    public function handle(): void {}
};
PHP;

            file_put_contents($tempDir.'/2099_05_01_120000_CapitalizeTestOperation'.$uniqueId.'.php', $operation);

            config()->set('sequencer.execution.discovery_paths', [$tempDir]);

            // Act
            $exitCode = Artisan::call('sequencer:process', [
                '--dry-run' => true,
                '--from' => '2099_05_01_000000',
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $output = Artisan::output();
            // ucfirst() is applied to type, so 'operation' becomes 'Operation'
            expect($output)->toContain('Operation');
            expect($output)->toContain('Found 1 pending task');

            // Cleanup
            array_map(unlink(...), glob($tempDir.'/*'));
            rmdir($tempDir);
        })->group('happy-path', 'dry-run');

        test('dry-run table displays all task array fields - Lines 106-110', function (): void {
            // Arrange
            $uniqueId = uniqid();
            $tempDir = storage_path('framework/testing/operations_fields_'.$uniqueId);
            mkdir($tempDir, 0o755, true);

            $operation = <<<'PHP'
<?php
namespace App\Operations;
use Cline\Sequencer\Contracts\Operation;
return new class implements Operation {
    public function handle(): void {}
};
PHP;

            file_put_contents($tempDir.'/2099_06_01_123456_TaskFieldsOperation'.$uniqueId.'.php', $operation);

            config()->set('sequencer.execution.discovery_paths', [$tempDir]);

            // Act
            $exitCode = Artisan::call('sequencer:process', [
                '--dry-run' => true,
                '--from' => '2099_06_01_000000',
            ]);

            // Assert
            expect($exitCode)->toBe(0);
            $output = Artisan::output();
            // Verify all three fields from task array are displayed
            expect($output)->toContain('Operation'); // type (capitalized)
            expect($output)->toContain('2099_06_01_123456'); // timestamp
            expect($output)->toContain('TaskFieldsOperation'); // name

            // Cleanup
            array_map(unlink(...), glob($tempDir.'/*'));
            rmdir($tempDir);
        })->group('happy-path', 'dry-run');
    });
});
