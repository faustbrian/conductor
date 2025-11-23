<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Support\OperationDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->discovery = new OperationDiscovery();
    $this->testPath = storage_path('framework/testing/operations');

    // Ensure test directory exists and is clean
    if (File::exists($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }

    File::makeDirectory($this->testPath, 0o755, true);

    // Configure discovery paths
    Config::set('sequencer.execution.discovery_paths', [$this->testPath]);
});
afterEach(function (): void {
    // Clean up test directory
    if (File::exists($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});
test('discovers pending operations successfully', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $this->testPath);
    createOperationFile('2024_01_17_140000_SeedDefaultData.php', $this->testPath);

    // Mark one as executed - must use full filename
    Operation::query()->create([
        'name' => '2024_01_15_120000_CreateUsersTable.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => now(),
    ]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(2);
    expect($result[0]['class'])->toContain('2024_01_16_130000_AddEmailColumn.php');
    expect($result[1]['class'])->toContain('2024_01_17_140000_SeedDefaultData.php');
})->group('happy-path');
test('returns all operations when none executed', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $this->testPath);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(2);
    expect($result[0]['class'])->toContain('CreateUsersTable.php');
    expect($result[1]['class'])->toContain('AddEmailColumn.php');
})->group('happy-path');
test('returns empty array when all operations executed', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $this->testPath);

    // Mark all as executed - use class names to match the comparison logic
    Operation::query()->create([
        'name' => '2024_01_15_120000_CreateUsersTable.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => now(),
    ]);

    Operation::query()->create([
        'name' => '2024_01_16_130000_AddEmailColumn.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => now(),
    ]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(0);
    expect($result)->toBe([]);
})->group('happy-path');
test('parses operation filenames correctly', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['class'])->toContain('CreateUsersTable.php');
    expect($result[0]['name'])->toBe('2024_01_15_120000_CreateUsersTable.php');
    expect($result[0]['timestamp'])->toBe('2024_01_15_120000');
    expect($result[0]['path'])->toEndWith('2024_01_15_120000_CreateUsersTable.php');
})->group('happy-path');
test('returns empty array when discovery paths do not exist', function (): void {
    // Arrange
    Config::set('sequencer.execution.discovery_paths', ['/non/existent/path']);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(0);
    expect($result)->toBe([]);
})->group('sad-path');
test('skips files that do not match naming pattern', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    // Valid
    createOperationFile('InvalidFileName.php', $this->testPath);
    // Invalid
    createOperationFile('2024_CreateUsersTable.php', $this->testPath);
    // Invalid
    createOperationFile('CreateUsersTable.php', $this->testPath);

    // Invalid
    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['class'])->toContain('CreateUsersTable.php');
})->group('sad-path');
test('handles empty discovery paths configuration', function (): void {
    // Arrange
    Config::set('sequencer.execution.discovery_paths', []);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(0);
    expect($result)->toBe([]);
})->group('sad-path');
test('handles multiple discovery paths', function (): void {
    // Arrange
    $testPath2 = storage_path('framework/testing/operations2');
    File::makeDirectory($testPath2, 0o755, true);

    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $testPath2);

    Config::set('sequencer.execution.discovery_paths', [$this->testPath, $testPath2]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(2);

    // Cleanup
    File::deleteDirectory($testPath2);
})->group('edge-case');
test('handles mixed valid and invalid filenames', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    // Valid
    createOperationFile('InvalidFile.php', $this->testPath);
    // Invalid
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $this->testPath);
    // Valid
    createOperationFile('2024-01-17.php', $this->testPath);

    // Invalid
    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(2);
    expect($result[0]['class'])->toContain('CreateUsersTable.php');
    expect($result[1]['class'])->toContain('AddEmailColumn.php');
})->group('edge-case');
test('handles operations with underscores in names', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_create_users_table.php', $this->testPath);
    createOperationFile('2024_01_16_130000_add_email_column_to_users.php', $this->testPath);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(2);
    expect($result[0]['class'])->toContain('create_users_table.php');
    expect($result[1]['class'])->toContain('add_email_column_to_users.php');
})->group('edge-case');
test('returns empty array when discovery path is empty directory', function (): void {
    // Arrange
    // testPath exists but is empty (no files created)
    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(0);
    expect($result)->toBe([]);
})->group('edge-case');
test('database integration with real queries', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $this->testPath);

    // Mark one as executed with completed_at - use class name to match comparison logic
    Operation::query()->create([
        'name' => '2024_01_15_120000_CreateUsersTable.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => now(),
    ]);

    // Create one executed but not completed (should not be in executed list)
    Operation::query()->create([
        'name' => '2024_01_16_130000_AddEmailColumn.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => null,
    ]);

    // Verify database state
    $this->assertDatabaseHas('operations', [
        'name' => '2024_01_15_120000_CreateUsersTable.php',
    ]);
    $this->assertDatabaseCount('operations', 2);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['class'])->toContain('AddEmailColumn.php');
})->group('integration');
test('filesystem integration with real files', function (): void {
    // Arrange
    $operations = [
        '2024_01_15_120000_CreateUsersTable.php',
        '2024_01_16_130000_AddEmailColumn.php',
        '2024_01_17_140000_SeedDefaultData.php',
    ];

    foreach ($operations as $operation) {
        createOperationFile($operation, $this->testPath);
    }

    // Verify files actually exist
    foreach ($operations as $operation) {
        expect($this->testPath.'/'.$operation)->toBeFile();
    }

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(3);

    foreach ($result as $operation) {
        expect($operation['path'])->toBeFile();
    }
})->group('integration');

test('repeat mode returns all operations when all have been executed', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $this->testPath);

    // Mark all as executed
    Operation::query()->create([
        'name' => '2024_01_15_120000_CreateUsersTable.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => now(),
    ]);

    Operation::query()->create([
        'name' => '2024_01_16_130000_AddEmailColumn.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => now(),
    ]);

    // Act
    $result = $this->discovery->getPending(repeat: true);

    // Assert - Should return all operations for re-execution
    expect($result)->toHaveCount(2);
    expect($result[0]['class'])->toContain('CreateUsersTable.php');
    expect($result[1]['class'])->toContain('AddEmailColumn.php');
})->group('happy-path');

test('repeat mode throws exception when operation has never been executed', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);
    createOperationFile('2024_01_16_130000_AddEmailColumn.php', $this->testPath);

    // Mark only one as executed
    Operation::query()->create([
        'name' => '2024_01_15_120000_CreateUsersTable.php',
        'type' => 'sync',
        'executed_at' => now(),
        'completed_at' => now(),
    ]);

    // Act & Assert - Should throw because AddEmailColumn has never been executed
    expect(fn () => $this->discovery->getPending(repeat: true))
        ->toThrow(
            RuntimeException::class,
            "Operation '2024_01_16_130000_AddEmailColumn.php' has never been executed",
        );
})->group('sad-path');

test('repeat mode throws exception when no operations have been executed', function (): void {
    // Arrange
    createOperationFile('2024_01_15_120000_CreateUsersTable.php', $this->testPath);

    // Act & Assert - Should throw because operation has never been executed
    expect(fn () => $this->discovery->getPending(repeat: true))
        ->toThrow(
            RuntimeException::class,
            "Operation '2024_01_15_120000_CreateUsersTable.php' has never been executed",
        );
})->group('sad-path');
