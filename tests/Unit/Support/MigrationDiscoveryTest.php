<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Support\MigrationDiscovery;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

beforeEach(function (): void {
    // Arrange
    $this->migrator = $this->createMock(Migrator::class);
    $this->repository = $this->createMock(DatabaseMigrationRepository::class);
    $this->defaultMigrationsPath = database_path('migrations');

    $this->migrator
        ->method('getRepository')
        ->willReturn($this->repository);

    $this->discovery = new MigrationDiscovery($this->migrator);
});
test('returns empty array when no migration paths and default path does not exist', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    $this->migrator
        ->method('paths')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toBe([]);
})->group('happy-path');
test('returns empty array when migration path is empty directory', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([]);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toBe([]);
})->group('happy-path');
test('returns pending migration with extracted timestamp', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn(['2024_01_15_120000_create_users_table' => '/path/to/migrations/2024_01_15_120000_create_users_table.php']);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('2024_01_15_120000_create_users_table');
    expect($result[0]['timestamp'])->toBe('2024_01_15_120000');
    expect($result[0]['path'])->toBe('/path/to/migrations/2024_01_15_120000_create_users_table.php');
})->group('happy-path');
test('returns multiple pending migrations with correct timestamps', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([
            '2024_01_15_120000_create_users_table' => '/path/to/migrations/2024_01_15_120000_create_users_table.php',
            '2024_01_15_123000_create_posts_table' => '/path/to/migrations/2024_01_15_123000_create_posts_table.php',
            '2024_01_15_130000_create_comments_table' => '/path/to/migrations/2024_01_15_130000_create_comments_table.php',
        ]);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(3);
    expect($result[0]['name'])->toBe('2024_01_15_120000_create_users_table');
    expect($result[0]['timestamp'])->toBe('2024_01_15_120000');
    expect($result[1]['name'])->toBe('2024_01_15_123000_create_posts_table');
    expect($result[1]['timestamp'])->toBe('2024_01_15_123000');
    expect($result[2]['name'])->toBe('2024_01_15_130000_create_comments_table');
    expect($result[2]['timestamp'])->toBe('2024_01_15_130000');
})->group('happy-path');
test('excludes already ran migrations from pending list', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([
            '2024_01_15_120000_create_users_table' => '/path/to/migrations/2024_01_15_120000_create_users_table.php',
            '2024_01_15_123000_create_posts_table' => '/path/to/migrations/2024_01_15_123000_create_posts_table.php',
            '2024_01_15_130000_create_comments_table' => '/path/to/migrations/2024_01_15_130000_create_comments_table.php',
        ]);

    $this->repository
        ->method('getRan')
        ->willReturn([
            '2024_01_15_120000_create_users_table',
            '2024_01_15_130000_create_comments_table',
        ]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('2024_01_15_123000_create_posts_table');
    expect($result[0]['timestamp'])->toBe('2024_01_15_123000');
})->group('happy-path');
test('handles multiple migration paths correctly', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations1')
        ->andReturn(true);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations2')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations1', '/path/to/migrations2']);

    $this->migrator
        ->method('getMigrationFiles')
        ->willReturnCallback(function ($path): array {
            if ($path === '/path/to/migrations1') {
                return ['2024_01_15_120000_create_users_table' => '/path/to/migrations1/2024_01_15_120000_create_users_table.php'];
            }

            return ['2024_01_15_123000_create_posts_table' => '/path/to/migrations2/2024_01_15_123000_create_posts_table.php'];
        });

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(2);
    expect($result[0]['name'])->toBe('2024_01_15_120000_create_users_table');
    expect($result[0]['path'])->toBe('/path/to/migrations1/2024_01_15_120000_create_users_table.php');
    expect($result[1]['name'])->toBe('2024_01_15_123000_create_posts_table');
    expect($result[1]['path'])->toBe('/path/to/migrations2/2024_01_15_123000_create_posts_table.php');
})->group('happy-path');
test('skips invalid paths that are not directories', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/invalid/path')
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/valid/path')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/invalid/path', '/valid/path']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/valid/path')
        ->willReturn(['2024_01_15_120000_create_users_table' => '/valid/path/2024_01_15_120000_create_users_table.php']);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('2024_01_15_120000_create_users_table');
    expect($result[0]['path'])->toBe('/valid/path/2024_01_15_120000_create_users_table.php');
})->group('sad-path');
test('skips files without valid timestamp format', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([
            'invalid_migration_file' => '/path/to/migrations/invalid_migration_file.php',
            '2024_01_15_create_users_table' => '/path/to/migrations/2024_01_15_create_users_table.php',
            'create_users_table' => '/path/to/migrations/create_users_table.php',
        ]);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toBe([]);
})->group('sad-path');
test('returns empty array when all migrations already ran', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([
            '2024_01_15_120000_create_users_table' => '/path/to/migrations/2024_01_15_120000_create_users_table.php',
            '2024_01_15_123000_create_posts_table' => '/path/to/migrations/2024_01_15_123000_create_posts_table.php',
        ]);

    $this->repository
        ->method('getRan')
        ->willReturn([
            '2024_01_15_120000_create_users_table',
            '2024_01_15_123000_create_posts_table',
        ]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toBe([]);
})->group('sad-path');
test('extracts timestamp correctly with different date formats', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([
            '2024_12_31_235959_new_year_migration' => '/path/to/migrations/2024_12_31_235959_new_year_migration.php',
            '2024_01_01_000000_first_day_migration' => '/path/to/migrations/2024_01_01_000000_first_day_migration.php',
        ]);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(2);
    expect($result[0]['timestamp'])->toBe('2024_12_31_235959');
    expect($result[1]['timestamp'])->toBe('2024_01_01_000000');
})->group('edge-case');
test('returns correct path structure with php extension', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/custom/path/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/custom/path/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/custom/path/migrations')
        ->willReturn(['2024_01_15_120000_create_users_table' => '/custom/path/migrations/2024_01_15_120000_create_users_table.php']);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['path'])->toBe('/custom/path/migrations/2024_01_15_120000_create_users_table.php');
    expect($result[0]['path'])->toEndWith('.php');
})->group('edge-case');
test('handles migration names with underscores and numbers', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([
            '2024_01_15_120000_create_users_v2_table' => '/path/to/migrations/2024_01_15_120000_create_users_v2_table.php',
            '2024_01_15_123000_add_column_to_posts_table' => '/path/to/migrations/2024_01_15_123000_add_column_to_posts_table.php',
            '2024_01_15_130000_update_users_2024_data' => '/path/to/migrations/2024_01_15_130000_update_users_2024_data.php',
        ]);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(3);
    expect($result[0]['name'])->toBe('2024_01_15_120000_create_users_v2_table');
    expect($result[1]['name'])->toBe('2024_01_15_123000_add_column_to_posts_table');
    expect($result[2]['name'])->toBe('2024_01_15_130000_update_users_2024_data');
})->group('edge-case');
test('handles empty path after skipping non directories', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/file/not/directory')
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/another/file')
        ->andReturn(false);

    $this->migrator
        ->method('paths')
        ->willReturn(['/file/not/directory', '/another/file']);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toBe([]);
})->group('edge-case');
test('validates timestamp regex pattern matches correct format', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn([
            '2024_01_15_120000_valid_migration' => '/path/to/migrations/2024_01_15_120000_valid_migration.php',
            '24_01_15_120000_invalid_year' => '/path/to/migrations/24_01_15_120000_invalid_year.php',
            '2024_1_15_120000_invalid_month' => '/path/to/migrations/2024_1_15_120000_invalid_month.php',
            '2024_01_5_120000_invalid_day' => '/path/to/migrations/2024_01_5_120000_invalid_day.php',
            '2024_01_15_12000_invalid_time' => '/path/to/migrations/2024_01_15_12000_invalid_time.php',
        ]);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('2024_01_15_120000_valid_migration');
})->group('edge-case');
test('returns array with exact structure', function (): void {
    // Arrange
    File::shouldReceive('isDirectory')
        ->once()
        ->with($this->defaultMigrationsPath)
        ->andReturn(false);

    File::shouldReceive('isDirectory')
        ->once()
        ->with('/path/to/migrations')
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn(['/path/to/migrations']);

    $this->migrator
        ->method('getMigrationFiles')
        ->with('/path/to/migrations')
        ->willReturn(['2024_01_15_120000_create_users_table' => '/path/to/migrations/2024_01_15_120000_create_users_table.php']);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0])->toHaveKey('name');
    expect($result[0])->toHaveKey('timestamp');
    expect($result[0])->toHaveKey('path');
    expect($result[0])->toHaveCount(3);
})->group('edge-case');

test('includes default database migrations path even when migrator paths is empty', function (): void {
    // Arrange: migrator returns no paths, but default database/migrations should still be scanned
    $defaultMigrationsPath = database_path('migrations');

    File::shouldReceive('isDirectory')
        ->once()
        ->with($defaultMigrationsPath)
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn([]);

    $this->migrator
        ->method('getMigrationFiles')
        ->with($defaultMigrationsPath)
        ->willReturn(['2025_11_28_143813_rename_operations' => $defaultMigrationsPath.'/2025_11_28_143813_rename_operations.php']);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert: migration from default path should be discovered
    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('2025_11_28_143813_rename_operations');
    expect($result[0]['timestamp'])->toBe('2025_11_28_143813');
    expect($result[0]['path'])->toBe($defaultMigrationsPath.'/2025_11_28_143813_rename_operations.php');
})->group('regression');

test('includes default database migrations path alongside custom paths', function (): void {
    // Arrange: migrator returns custom path, default database/migrations should also be scanned
    $defaultMigrationsPath = database_path('migrations');
    $customPath = '/custom/migrations';

    File::shouldReceive('isDirectory')
        ->once()
        ->with($defaultMigrationsPath)
        ->andReturn(true);

    File::shouldReceive('isDirectory')
        ->once()
        ->with($customPath)
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn([$customPath]);

    $this->migrator
        ->method('getMigrationFiles')
        ->willReturnCallback(function ($path) use ($defaultMigrationsPath, $customPath): array {
            if ($path === $defaultMigrationsPath) {
                return ['2025_11_28_143813_rename_operations' => $defaultMigrationsPath.'/2025_11_28_143813_rename_operations.php'];
            }

            if ($path === $customPath) {
                return ['2025_11_28_150000_custom_migration' => $customPath.'/2025_11_28_150000_custom_migration.php'];
            }

            return [];
        });

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert: migrations from both paths should be discovered
    expect($result)->toHaveCount(2);
    expect($result[0]['name'])->toBe('2025_11_28_143813_rename_operations');
    expect($result[1]['name'])->toBe('2025_11_28_150000_custom_migration');
})->group('regression');

test('deduplicates default path when migrator paths already includes it', function (): void {
    // Arrange: migrator returns default path explicitly, should not scan twice
    $defaultMigrationsPath = database_path('migrations');

    File::shouldReceive('isDirectory')
        ->once()
        ->with($defaultMigrationsPath)
        ->andReturn(true);

    $this->migrator
        ->method('paths')
        ->willReturn([$defaultMigrationsPath]);

    $this->migrator
        ->method('getMigrationFiles')
        ->with($defaultMigrationsPath)
        ->willReturn(['2025_11_28_143813_rename_operations' => $defaultMigrationsPath.'/2025_11_28_143813_rename_operations.php']);

    $this->repository
        ->method('getRan')
        ->willReturn([]);

    // Act
    $result = $this->discovery->getPending();

    // Assert: migration should only appear once (no duplicates from scanning same path twice)
    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('2025_11_28_143813_rename_operations');
})->group('regression');
