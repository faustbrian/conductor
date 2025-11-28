<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->operation = new Operation();
});
test('model can be instantiated', function (): void {
    // Arrange & Act
    $operation = new Operation();

    // Assert
    expect($operation)->toBeInstanceOf(Operation::class);
})->group('happy-path');
test('get table returns configured table name', function (): void {
    // Arrange
    Config::set('sequencer.table_names.operations', 'custom_operations');

    // Act
    $tableName = $this->operation->getTable();

    // Assert
    expect($tableName)->toEqual('custom_operations');
})->group('happy-path');
test('get table returns default table name when config is null', function (): void {
    // Arrange
    Config::set('sequencer.table_names', []);

    // Act
    $tableName = $this->operation->getTable();

    // Assert
    expect($tableName)->toEqual('operations');
})->group('edge-case');
test('fillable attributes contains all expected fields', function (): void {
    // Arrange
    $expectedFillable = [
        'name',
        'type',
        'executed_by_type',
        'executed_by_id',
        'executed_at',
        'completed_at',
        'failed_at',
        'skipped_at',
        'skip_reason',
        'rolled_back_at',
        'state',
    ];

    // Act
    $fillable = $this->operation->getFillable();

    // Assert
    expect($fillable)->toEqual($expectedFillable);
})->group('happy-path');
test('casts configuration contains datetime casts', function (): void {
    // Arrange
    $expectedCasts = [
        'executed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    // Act
    $casts = $this->operation->getCasts();

    // Assert
    foreach ($expectedCasts as $attribute => $cast) {
        expect($casts)->toHaveKey($attribute);
        expect($casts[$attribute])->toEqual($cast);
    }
})->group('happy-path');
test('errors relationship returns has many relation', function (): void {
    // Arrange
    Config::set('sequencer.models.operation_error', OperationError::class);

    // Act
    $relation = $this->operation->errors();

    // Assert
    expect($relation)->toBeInstanceOf(HasMany::class);
    expect($relation->getRelated())->toBeInstanceOf(OperationError::class);
})->group('happy-path');
test('errors relationship returns errors from database', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
    ]);

    OperationError::query()->create([
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'Test error',
        'trace' => 'Stack trace here',
        'created_at' => Date::now(),
    ]);

    // Act
    $errors = $operation->errors;

    // Assert
    expect($errors)->toHaveCount(1);
    expect($errors->first()->exception)->toEqual('RuntimeException');
    $this->assertDatabaseHas('operation_errors', [
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'Test error',
    ]);
})->group('happy-path');
test('executed by relationship returns morph to relation', function (): void {
    // Arrange & Act
    $relation = $this->operation->executedBy();

    // Assert
    expect($relation)->toBeInstanceOf(MorphTo::class);
})->group('happy-path');
test('primary key behavior with auto increment configuration', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'id');
    $operation = new Operation();

    // Act
    $isIncrementing = $operation->getIncrementing();
    $keyType = $operation->getKeyType();

    // Assert
    expect($isIncrementing)->toBeTrue();
    expect($keyType)->toEqual('int');
})->group('happy-path');
test('primary key behavior with uuid configuration', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'uuid');
    $operation = new Operation();

    // Act
    $isIncrementing = $operation->getIncrementing();
    $keyType = $operation->getKeyType();
    $uniqueIds = $operation->uniqueIds();

    // Assert
    expect($isIncrementing)->toBeFalse();
    expect($keyType)->toEqual('string');
    expect($uniqueIds)->toEqual(['id']);
})->group('happy-path');
test('primary key behavior with ulid configuration', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'ulid');
    $operation = new Operation();

    // Act
    $isIncrementing = $operation->getIncrementing();
    $keyType = $operation->getKeyType();
    $uniqueIds = $operation->uniqueIds();

    // Assert
    expect($isIncrementing)->toBeFalse();
    expect($keyType)->toEqual('string');
    expect($uniqueIds)->toEqual(['id']);
})->group('happy-path');
test('timestamps property is false', function (): void {
    // Arrange & Act
    $timestamps = $this->operation->timestamps;

    // Assert
    expect($timestamps)->toBeFalse();
})->group('happy-path');
test('errors relationship returns empty collection when no errors', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
    ]);

    // Act
    $errors = $operation->errors;

    // Assert
    expect($errors)->toHaveCount(0);
    expect($errors->isEmpty())->toBeTrue();
    $this->assertDatabaseCount('operation_errors', 0);
})->group('edge-case');
test('executed by can be null', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
    ]);

    // Act
    $executedBy = $operation->executedBy;

    // Assert
    expect($executedBy)->toBeNull();
})->group('edge-case');
test('operation can have multiple errors', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
    ]);

    OperationError::query()->create([
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'First error',
        'trace' => 'Stack trace 1',
        'created_at' => Date::now(),
    ]);

    OperationError::query()->create([
        'operation_id' => $operation->id,
        'exception' => 'InvalidArgumentException',
        'message' => 'Second error',
        'trace' => 'Stack trace 2',
        'created_at' => Date::now(),
    ]);

    // Act
    $errors = $operation->errors;

    // Assert
    expect($errors)->toHaveCount(2);
    expect($errors->first()->exception)->toEqual('RuntimeException');
    expect($errors->last()->exception)->toEqual('InvalidArgumentException');
    $this->assertDatabaseCount('operation_errors', 2);
})->group('edge-case');
