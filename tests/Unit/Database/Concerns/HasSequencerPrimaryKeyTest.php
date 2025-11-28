<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Exceptions\InvalidPrimaryKeyValueException;

test('returns incrementing true for id type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'id']);
    $model = createTestModel();

    // Act
    $result = $model->getIncrementing();

    // Assert
    expect($result)->toBeTrue();
})->group('id-type');
test('returns int key type for id type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'id']);
    $model = createTestModel();

    // Act
    $result = $model->getKeyType();

    // Assert
    expect($result)->toBe('int');
})->group('id-type');
test('returns empty unique ids for id type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'id']);
    $model = createTestModel();

    // Act
    $result = $model->uniqueIds();

    // Assert
    expect($result)->toBe([]);
})->group('id-type');
test('returns null for new unique id with id type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'id']);
    $model = createTestModel();

    // Act
    $result = $model->newUniqueId();

    // Assert
    expect($result)->toBeNull();
})->group('id-type');
test('returns incrementing false for ulid type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'ulid']);
    $model = createTestModel();

    // Act
    $result = $model->getIncrementing();

    // Assert
    expect($result)->toBeFalse();
})->group('ulid-type');
test('returns string key type for ulid type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'ulid']);
    $model = createTestModel();

    // Act
    $result = $model->getKeyType();

    // Assert
    expect($result)->toBe('string');
})->group('ulid-type');
test('returns key name in unique ids for ulid type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'ulid']);
    $model = createTestModel();

    // Act
    $result = $model->uniqueIds();

    // Assert
    expect($result)->toBe(['id']);
})->group('ulid-type');
test('generates valid ulid for new unique id', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'ulid']);
    $model = createTestModel();

    // Act
    $result = $model->newUniqueId();

    // Assert
    expect($result)->toBeString();
    expect(mb_strlen($result))->toBe(26);
    expect($result)->toMatch('/^[0123456789abcdefghjkmnpqrstvwxyz]{26}$/');
})->group('ulid-type');
test('auto generates ulid when not set', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'ulid']);
    $model = createTestModel();

    // Act
    simulateCreatingEvent($model);

    // Assert
    expect($model->getKey())->toBeString();
    expect(mb_strlen((string) $model->getKey()))->toBe(26);
})->group('ulid-type');
test('preserves preset ulid value', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'ulid']);
    $presetUlid = '01hqz8x9v5k3j2m1n0p9q8r7s6';
    $model = createTestModel();
    $model->setAttribute('id', $presetUlid);

    // Act
    simulateCreatingEvent($model);

    // Assert
    expect($model->getKey())->toBe($presetUlid);
})->group('ulid-type');
test('throws exception for non string ulid value', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'ulid']);
    $model = createTestModel();
    $model->setAttribute('id', 12_345);

    // Act & Assert
    $this->expectException(InvalidPrimaryKeyValueException::class);
    $this->expectExceptionMessage('Cannot assign non-string value to ULID primary key');
    simulateCreatingEvent($model);
})->group('ulid-type');
test('returns incrementing false for uuid type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'uuid']);
    $model = createTestModel();

    // Act
    $result = $model->getIncrementing();

    // Assert
    expect($result)->toBeFalse();
})->group('uuid-type');
test('returns string key type for uuid type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'uuid']);
    $model = createTestModel();

    // Act
    $result = $model->getKeyType();

    // Assert
    expect($result)->toBe('string');
})->group('uuid-type');
test('returns key name in unique ids for uuid type', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'uuid']);
    $model = createTestModel();

    // Act
    $result = $model->uniqueIds();

    // Assert
    expect($result)->toBe(['id']);
})->group('uuid-type');
test('generates valid uuid for new unique id', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'uuid']);
    $model = createTestModel();

    // Act
    $result = $model->newUniqueId();

    // Assert
    expect($result)->toBeString();
    expect(mb_strlen($result))->toBe(36);
    expect($result)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
})->group('uuid-type');
test('auto generates uuid when not set', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'uuid']);
    $model = createTestModel();

    // Act
    simulateCreatingEvent($model);

    // Assert
    expect($model->getKey())->toBeString();
    expect(mb_strlen((string) $model->getKey()))->toBe(36);
})->group('uuid-type');
test('preserves preset uuid value', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'uuid']);
    $presetUuid = '550e8400-e29b-41d4-a716-446655440000';
    $model = createTestModel();
    $model->setAttribute('id', $presetUuid);

    // Act
    simulateCreatingEvent($model);

    // Assert
    expect($model->getKey())->toBe($presetUuid);
})->group('uuid-type');
test('throws exception for non string uuid value', function (): void {
    // Arrange
    config(['sequencer.primary_key_type' => 'uuid']);
    $model = createTestModel();
    $model->setAttribute('id', 12_345);

    // Act & Assert
    $this->expectException(InvalidPrimaryKeyValueException::class);
    $this->expectExceptionMessage('Cannot assign non-string value to UUID primary key');
    simulateCreatingEvent($model);
})->group('uuid-type');
