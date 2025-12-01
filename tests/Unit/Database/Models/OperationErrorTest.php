<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\Enums\OperationState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->operationError = new OperationError();
});
test('model can be instantiated', function (): void {
    // Arrange & Act
    $operationError = new OperationError();

    // Assert
    expect($operationError)->toBeInstanceOf(OperationError::class);
})->group('happy-path');
test('get table returns configured table name', function (): void {
    // Arrange
    Config::set('sequencer.table_names.operation_errors', 'custom_operation_errors');

    // Act
    $tableName = $this->operationError->getTable();

    // Assert
    expect($tableName)->toEqual('custom_operation_errors');
})->group('happy-path');
test('get table returns default table name when config is null', function (): void {
    // Arrange
    Config::set('sequencer.table_names', []);

    // Act
    $tableName = $this->operationError->getTable();

    // Assert
    expect($tableName)->toEqual('operation_errors');
})->group('edge-case');
test('fillable attributes contains all expected fields', function (): void {
    // Arrange
    $expectedFillable = [
        'operation_id',
        'exception',
        'message',
        'trace',
        'context',
        'created_at',
    ];

    // Act
    $fillable = $this->operationError->getFillable();

    // Assert
    expect($fillable)->toEqual($expectedFillable);
})->group('happy-path');
test('casts configuration contains array and datetime casts', function (): void {
    // Arrange
    $expectedCasts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    // Act
    $casts = $this->operationError->getCasts();

    // Assert
    foreach ($expectedCasts as $attribute => $cast) {
        expect($casts)->toHaveKey($attribute);
        expect($casts[$attribute])->toEqual($cast);
    }
})->group('happy-path');
test('operation relationship returns belongs to relation', function (): void {
    // Arrange
    Config::set('sequencer.models.operation', Operation::class);

    // Act
    $relation = $this->operationError->operation();

    // Assert
    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Operation::class);
})->group('happy-path');
test('operation error can be created with all attributes', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
        'state' => OperationState::Pending,
    ]);

    $errorData = [
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'Something went wrong',
        'trace' => 'Stack trace here',
        'context' => ['key' => 'value', 'user_id' => 123],
        'created_at' => Date::now(),
    ];

    // Act
    $operationError = OperationError::query()->create($errorData);

    // Assert
    expect($operationError)->toBeInstanceOf(OperationError::class);
    expect($operationError->exception)->toEqual('RuntimeException');
    expect($operationError->message)->toEqual('Something went wrong');
    expect($operationError->trace)->toEqual('Stack trace here');
    expect($operationError->context)->toBeArray();
    expect($operationError->context)->toEqual(['key' => 'value', 'user_id' => 123]);
    expect($operationError->created_at)->toBeInstanceOf(Carbon::class);
})->group('happy-path');
test('context is cast to array', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
        'state' => OperationState::Pending,
    ]);

    $contextData = ['request_id' => 'abc-123', 'user_agent' => 'Mozilla'];

    // Act
    $operationError = OperationError::query()->create([
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'Error message',
        'trace' => 'Stack trace',
        'context' => $contextData,
        'created_at' => Date::now(),
    ]);

    // Assert
    expect($operationError->context)->toBeArray();
    expect($operationError->context)->toEqual($contextData);
    $this->assertDatabaseHas('operation_errors', [
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
    ]);
})->group('happy-path');
test('operation relationship returns operation from database', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'async',
        'executed_at' => Date::now(),
        'state' => OperationState::Pending,
    ]);

    $operationError = OperationError::query()->create([
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'Test error',
        'trace' => 'Stack trace',
        'created_at' => Date::now(),
    ]);

    // Act
    $relatedOperation = $operationError->operation;

    // Assert
    expect($relatedOperation)->toBeInstanceOf(Operation::class);
    expect($relatedOperation->id)->toEqual($operation->id);
    expect($relatedOperation->name)->toEqual('TestOperation');
    expect($relatedOperation->type)->toEqual('async');
})->group('happy-path');
test('primary key behavior with auto increment configuration', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'id');
    $operationError = new OperationError();

    // Act
    $isIncrementing = $operationError->getIncrementing();
    $keyType = $operationError->getKeyType();

    // Assert
    expect($isIncrementing)->toBeTrue();
    expect($keyType)->toEqual('int');
})->group('happy-path');
test('primary key behavior with uuid configuration', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'uuid');
    $operationError = new OperationError();

    // Act
    $isIncrementing = $operationError->getIncrementing();
    $keyType = $operationError->getKeyType();
    $uniqueIds = $operationError->uniqueIds();

    // Assert
    expect($isIncrementing)->toBeFalse();
    expect($keyType)->toEqual('string');
    expect($uniqueIds)->toEqual(['id']);
})->group('happy-path');
test('primary key behavior with ulid configuration', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'ulid');
    $operationError = new OperationError();

    // Act
    $isIncrementing = $operationError->getIncrementing();
    $keyType = $operationError->getKeyType();
    $uniqueIds = $operationError->uniqueIds();

    // Assert
    expect($isIncrementing)->toBeFalse();
    expect($keyType)->toEqual('string');
    expect($uniqueIds)->toEqual(['id']);
})->group('happy-path');
test('timestamps property is false', function (): void {
    // Arrange & Act
    $timestamps = $this->operationError->timestamps;

    // Assert
    expect($timestamps)->toBeFalse();
})->group('happy-path');
test('context can be null', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
        'state' => OperationState::Pending,
    ]);

    // Act
    $operationError = OperationError::query()->create([
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'Error without context',
        'trace' => 'Stack trace',
        'context' => null,
        'created_at' => Date::now(),
    ]);

    // Assert
    expect($operationError->context)->toBeNull();
})->group('edge-case');
test('context can be empty array', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
        'state' => OperationState::Pending,
    ]);

    // Act
    $operationError = OperationError::query()->create([
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
        'message' => 'Error with empty context',
        'trace' => 'Stack trace',
        'context' => [],
        'created_at' => Date::now(),
    ]);

    // Assert
    expect($operationError->context)->toBeArray();
    expect($operationError->context)->toBeEmpty();
    expect($operationError->context)->toEqual([]);
})->group('edge-case');
test('multiple errors can belong to same operation', function (): void {
    // Arrange
    $operation = Operation::query()->create([
        'name' => 'TestOperation',
        'type' => 'sync',
        'executed_at' => Date::now(),
        'state' => OperationState::Pending,
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
    $this->assertDatabaseCount('operation_errors', 2);
    $this->assertDatabaseHas('operation_errors', [
        'operation_id' => $operation->id,
        'exception' => 'RuntimeException',
    ]);
    $this->assertDatabaseHas('operation_errors', [
        'operation_id' => $operation->id,
        'exception' => 'InvalidArgumentException',
    ]);
})->group('edge-case');
