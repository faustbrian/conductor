<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Exceptions\CircularDependencyException;
use Cline\Sequencer\Support\DependencyResolver;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Operations\BasicOperation;
use Tests\Fixtures\Operations\CircularOperationA;
use Tests\Fixtures\Operations\CircularOperationB;
use Tests\Fixtures\Operations\DependentOperation;
use Tests\Fixtures\Operations\MigrationDependentOperation;
use Tests\Fixtures\Operations\MultiDependencyOperation;
use Tests\Fixtures\Operations\OperationA;
use Tests\Fixtures\Operations\OperationB;
use Tests\Fixtures\Operations\OperationC;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Arrange
    $this->migrator = app(Migrator::class);
    $this->resolver = new DependencyResolver($this->migrator);
});
test('returns true for operations without dependencies', function (): void {
    // Arrange
    $operation = new BasicOperation();

    // Act
    $result = $this->resolver->dependenciesSatisfied($operation);

    // Assert
    expect($result)->toBeTrue();
})->group('happy-path');
test('returns true when all dependencies are satisfied', function (): void {
    // Arrange
    OperationModel::factory()->create([
        'name' => BasicOperation::class,
        'completed_at' => now(),
    ]);
    $operation = new DependentOperation();

    // Act
    $result = $this->resolver->dependenciesSatisfied($operation);

    // Assert
    expect($result)->toBeTrue();
})->group('happy-path');
test('returns empty array for operations without dependencies', function (): void {
    // Arrange
    $operation = new BasicOperation();

    // Act
    $result = $this->resolver->getUnsatisfiedDependencies($operation);

    // Assert
    expect($result)->toBe([]);
})->group('happy-path');
test('sorts operations in correct dependency order', function (): void {
    // Arrange
    $tasks = [
        [
            'type' => 'operation',
            'timestamp' => '2024-01-02 00:00:00',
            'data' => ['class' => DependentOperation::class],
            'operation' => new DependentOperation(),
        ],
        [
            'type' => 'operation',
            'timestamp' => '2024-01-01 00:00:00',
            'data' => ['class' => BasicOperation::class],
            'operation' => new BasicOperation(),
        ],
    ];

    // Act
    $result = $this->resolver->sortByDependencies($tasks);

    // Assert
    expect($result[0]['data']['class'])->toBe(BasicOperation::class);
    expect($result[1]['data']['class'])->toBe(DependentOperation::class);
})->group('happy-path');
test('sorts parallel operations without dependencies', function (): void {
    // Arrange
    $tasks = [
        [
            'type' => 'operation',
            'timestamp' => '2024-01-01 00:00:00',
            'data' => ['class' => BasicOperation::class],
            'operation' => new BasicOperation(),
        ],
        [
            'type' => 'operation',
            'timestamp' => '2024-01-02 00:00:00',
            'data' => ['class' => OperationA::class],
            'operation' => new OperationA(),
        ],
    ];

    // Act
    $result = $this->resolver->sortByDependencies($tasks);

    // Assert
    expect($result)->toHaveCount(2);
    expect($result[0]['data']['class'])->toBe(BasicOperation::class);
    expect($result[1]['data']['class'])->toBe(OperationA::class);
})->group('happy-path');
test('handles migration dependencies correctly', function (): void {
    // Arrange
    $repository = $this->migrator->getRepository();
    $repository->log('2024_01_01_000000_create_users_table', 1);

    $operation = new MigrationDependentOperation();

    // Act
    $result = $this->resolver->dependenciesSatisfied($operation);

    // Assert
    expect($result)->toBeTrue();
})->group('happy-path');
test('sorts mixed migrations and operations', function (): void {
    // Arrange
    $tasks = [
        [
            'type' => 'operation',
            'timestamp' => '2024-01-02 00:00:00',
            'data' => ['class' => MigrationDependentOperation::class],
            'operation' => new MigrationDependentOperation(),
        ],
        [
            'type' => 'migration',
            'timestamp' => '2024-01-01 00:00:00',
            'data' => ['name' => '2024_01_01_000000_create_users_table'],
        ],
    ];

    // Act
    $result = $this->resolver->sortByDependencies($tasks);

    // Assert
    expect($result[0]['type'])->toBe('migration');
    expect($result[1]['type'])->toBe('operation');
})->group('happy-path');
test('returns false when dependencies not satisfied', function (): void {
    // Arrange
    $operation = new DependentOperation();

    // Act
    $result = $this->resolver->dependenciesSatisfied($operation);

    // Assert
    expect($result)->toBeFalse();
})->group('sad-path');
test('returns list of unsatisfied dependencies', function (): void {
    // Arrange
    $operation = new MultiDependencyOperation();

    // Act
    $result = $this->resolver->getUnsatisfiedDependencies($operation);

    // Assert
    expect($result)->toHaveCount(3);
    expect($result)->toContain(OperationA::class);
    expect($result)->toContain(OperationB::class);
    expect($result)->toContain('2024_01_01_000000_create_users_table');
})->group('sad-path');
test('throws exception for circular dependencies', function (): void {
    // Arrange
    $tasks = [
        [
            'type' => 'operation',
            'timestamp' => '2024-01-01 00:00:00',
            'data' => ['class' => CircularOperationA::class],
            'operation' => new CircularOperationA(),
        ],
        [
            'type' => 'operation',
            'timestamp' => '2024-01-02 00:00:00',
            'data' => ['class' => CircularOperationB::class],
            'operation' => new CircularOperationB(),
        ],
    ];
    $this->expectException(CircularDependencyException::class);

    // Act
    $this->resolver->sortByDependencies($tasks);

    // Assert
    // Exception expected above
})->group('sad-path');
test('detects migration dependency not run', function (): void {
    // Arrange
    $operation = new MigrationDependentOperation();

    // Act
    $result = $this->resolver->dependenciesSatisfied($operation);

    // Assert
    expect($result)->toBeFalse();
})->group('sad-path');
test('handles empty task array', function (): void {
    // Arrange
    $tasks = [];

    // Act
    $result = $this->resolver->sortByDependencies($tasks);

    // Assert
    expect($result)->toBe([]);
})->group('edge-case');
test('handles single operation with no dependencies', function (): void {
    // Arrange
    $tasks = [
        [
            'type' => 'operation',
            'timestamp' => '2024-01-01 00:00:00',
            'data' => ['class' => BasicOperation::class],
            'operation' => new BasicOperation(),
        ],
    ];

    // Act
    $result = $this->resolver->sortByDependencies($tasks);

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['data']['class'])->toBe(BasicOperation::class);
})->group('edge-case');
test('handles complex dependency chain', function (): void {
    // Arrange
    $tasks = [
        [
            'type' => 'operation',
            'timestamp' => '2024-01-03 00:00:00',
            'data' => ['class' => OperationC::class],
            'operation' => new OperationC(),
        ],
        [
            'type' => 'operation',
            'timestamp' => '2024-01-02 00:00:00',
            'data' => ['class' => OperationB::class],
            'operation' => new OperationB(),
        ],
        [
            'type' => 'operation',
            'timestamp' => '2024-01-01 00:00:00',
            'data' => ['class' => OperationA::class],
            'operation' => new OperationA(),
        ],
    ];

    // Act
    $result = $this->resolver->sortByDependencies($tasks);

    // Assert
    expect($result[0]['data']['class'])->toBe(OperationA::class);
    expect($result[1]['data']['class'])->toBe(OperationB::class);
    expect($result[2]['data']['class'])->toBe(OperationC::class);
})->group('edge-case');
test('handles partially satisfied dependencies', function (): void {
    // Arrange
    OperationModel::factory()->create([
        'name' => OperationA::class,
        'completed_at' => now(),
    ]);
    $operation = new MultiDependencyOperation();

    // Act
    $result = $this->resolver->getUnsatisfiedDependencies($operation);

    // Assert
    expect($result)->toHaveCount(2);
    expect($result)->toContain(OperationB::class);
    expect($result)->toContain('2024_01_01_000000_create_users_table');
    expect($result)->not->toContain(OperationA::class);
})->group('edge-case');
test('lazy loads operation instance when not pre instantiated', function (): void {
    // Arrange: Create a temp file with anonymous class
    $tempPath = storage_path('framework/testing/operations_'.uniqid());
    File::makeDirectory($tempPath, 0o755, true);

    $operationFile = $tempPath.'/2024_01_01_000000_basic_operation.php';
    File::put(
        $operationFile,
        <<<'PHP'
<?php
use Cline\Sequencer\Contracts\Operation;
return new class() implements Operation {
    public function handle(): void {}
};
PHP
    );

    $tasks = [
        [
            'type' => 'operation',
            'timestamp' => '2024-01-01 00:00:00',
            'data' => ['class' => $operationFile],
            // Note: No 'operation' key - should trigger lazy loading via require
        ],
    ];

    // Act
    $result = $this->resolver->sortByDependencies($tasks);

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['data']['class'])->toBe($operationFile);
    expect($result[0])->toHaveKey('operation');
    expect($result[0]['operation'])->toBeInstanceOf(Cline\Sequencer\Contracts\Operation::class);

    // Cleanup
    File::deleteDirectory($tempPath);
})->group('edge-case');
