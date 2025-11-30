<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\PrimaryKeyType;
use Cline\Sequencer\Support\PrimaryKeyValue;
use PHPUnit\Framework\Attributes\Test;

test('creates id type with null value', function (): void {
    // Arrange
    $type = PrimaryKeyType::ID;
    $value = null;

    // Act
    $primaryKey = new PrimaryKeyValue($type, $value);

    // Assert
    expect($primaryKey->type)->toBe(PrimaryKeyType::ID);
    expect($primaryKey->value)->toBeNull();
})->group('happy-path');
test('creates ulid type with string value', function (): void {
    // Arrange
    $type = PrimaryKeyType::ULID;
    $value = '01HQZX3Y4Z5A6B7C8D9E0F1G2H';

    // Act
    $primaryKey = new PrimaryKeyValue($type, $value);

    // Assert
    expect($primaryKey->type)->toBe(PrimaryKeyType::ULID);
    expect($primaryKey->value)->toBe('01HQZX3Y4Z5A6B7C8D9E0F1G2H');
})->group('happy-path');
test('creates uuid type with string value', function (): void {
    // Arrange
    $type = PrimaryKeyType::UUID;
    $value = '550e8400-e29b-41d4-a716-446655440000';

    // Act
    $primaryKey = new PrimaryKeyValue($type, $value);

    // Assert
    expect($primaryKey->type)->toBe(PrimaryKeyType::UUID);
    expect($primaryKey->value)->toBe('550e8400-e29b-41d4-a716-446655440000');
})->group('happy-path');
test('returns auto incrementing true for id type', function (): void {
    // Arrange
    $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ID, null);

    // Act
    $result = $primaryKey->isAutoIncrementing();

    // Assert
    expect($result)->toBeTrue();
})->group('happy-path');
test('returns auto incrementing false for ulid type', function (): void {
    // Arrange
    $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ULID, '01HQZX3Y4Z5A6B7C8D9E0F1G2H');

    // Act
    $result = $primaryKey->isAutoIncrementing();

    // Assert
    expect($result)->toBeFalse();
})->group('happy-path');
test('returns auto incrementing false for uuid type', function (): void {
    // Arrange
    $primaryKey = new PrimaryKeyValue(PrimaryKeyType::UUID, '550e8400-e29b-41d4-a716-446655440000');

    // Act
    $result = $primaryKey->isAutoIncrementing();

    // Assert
    expect($result)->toBeFalse();
})->group('happy-path');
test('returns requires value false for id type', function (): void {
    // Arrange
    $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ID, null);

    // Act
    $result = $primaryKey->requiresValue();

    // Assert
    expect($result)->toBeFalse();
})->group('happy-path');
test('returns requires value true for ulid type', function (): void {
    // Arrange
    $primaryKey = new PrimaryKeyValue(PrimaryKeyType::ULID, '01HQZX3Y4Z5A6B7C8D9E0F1G2H');

    // Act
    $result = $primaryKey->requiresValue();

    // Assert
    expect($result)->toBeTrue();
})->group('happy-path');
test('returns requires value true for uuid type', function (): void {
    // Arrange
    $primaryKey = new PrimaryKeyValue(PrimaryKeyType::UUID, '550e8400-e29b-41d4-a716-446655440000');

    // Act
    $result = $primaryKey->requiresValue();

    // Assert
    expect($result)->toBeTrue();
})->group('happy-path');
test('accepts id type with non null value', function (): void {
    // Arrange
    $type = PrimaryKeyType::ID;
    $value = '12345';

    // Act
    $primaryKey = new PrimaryKeyValue($type, $value);

    // Assert
    expect($primaryKey->type)->toBe(PrimaryKeyType::ID);
    expect($primaryKey->value)->toBe('12345');
})->group('edge-case');
test('accepts ulid type with null value', function (): void {
    // Arrange
    $type = PrimaryKeyType::ULID;
    $value = null;

    // Act
    $primaryKey = new PrimaryKeyValue($type, $value);

    // Assert
    expect($primaryKey->type)->toBe(PrimaryKeyType::ULID);
    expect($primaryKey->value)->toBeNull();
})->group('edge-case');
test('accepts uuid type with null value', function (): void {
    // Arrange
    $type = PrimaryKeyType::UUID;
    $value = null;

    // Act
    $primaryKey = new PrimaryKeyValue($type, $value);

    // Assert
    expect($primaryKey->type)->toBe(PrimaryKeyType::UUID);
    expect($primaryKey->value)->toBeNull();
})->group('edge-case');
