<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\PrimaryKeyType;
use Cline\Sequencer\Support\PrimaryKeyGenerator;
use Cline\Sequencer\Support\PrimaryKeyValue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

test('generates id type with null value', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'id');

    // Act
    $result = PrimaryKeyGenerator::generate();

    // Assert
    expect($result)->toBeInstanceOf(PrimaryKeyValue::class);
    expect($result->type)->toBe(PrimaryKeyType::ID);
    expect($result->value)->toBeNull();
})->group('happy-path');
test('generates ulid type with valid value', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'ulid');

    // Act
    $result = PrimaryKeyGenerator::generate();

    // Assert
    expect($result)->toBeInstanceOf(PrimaryKeyValue::class);
    expect($result->type)->toBe(PrimaryKeyType::ULID);
    expect($result->value)->not->toBeNull();
    expect(Str::isUlid($result->value))->toBeTrue();
    expect(mb_strlen((string) $result->value))->toBe(26);
    expect(mb_strtolower((string) $result->value))->toBe($result->value);
})->group('happy-path');
test('generates uuid type with valid value', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'uuid');

    // Act
    $result = PrimaryKeyGenerator::generate();

    // Assert
    expect($result)->toBeInstanceOf(PrimaryKeyValue::class);
    expect($result->type)->toBe(PrimaryKeyType::UUID);
    expect($result->value)->not->toBeNull();
    expect(Str::isUuid($result->value))->toBeTrue();
    expect(mb_strlen((string) $result->value))->toBe(36);
    expect(mb_strtolower((string) $result->value))->toBe($result->value);
})->group('happy-path');
test('falls back to id type when config value is invalid', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'invalid');

    // Act
    $result = PrimaryKeyGenerator::generate();

    // Assert
    expect($result)->toBeInstanceOf(PrimaryKeyValue::class);
    expect($result->type)->toBe(PrimaryKeyType::ID);
    expect($result->value)->toBeNull();
})->group('edge-case');
test('generates unique ulids for multiple calls', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'ulid');

    // Act
    $result1 = PrimaryKeyGenerator::generate();
    $result2 = PrimaryKeyGenerator::generate();
    $result3 = PrimaryKeyGenerator::generate();

    // Assert
    $this->assertNotSame($result1->value, $result2->value);
    $this->assertNotSame($result2->value, $result3->value);
    $this->assertNotSame($result1->value, $result3->value);
})->group('edge-case');
test('generates unique uuids for multiple calls', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'uuid');

    // Act
    $result1 = PrimaryKeyGenerator::generate();
    $result2 = PrimaryKeyGenerator::generate();
    $result3 = PrimaryKeyGenerator::generate();

    // Assert
    $this->assertNotSame($result1->value, $result2->value);
    $this->assertNotSame($result2->value, $result3->value);
    $this->assertNotSame($result1->value, $result3->value);
})->group('edge-case');
test('validates ulid format matches pattern', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'ulid');

    // Act
    $result = PrimaryKeyGenerator::generate();

    // Assert
    expect($result->value)->not->toBeNull();
    expect(Str::isUlid($result->value))->toBeTrue();
    expect($result->value)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/');
})->group('edge-case');
test('validates uuid format matches pattern', function (): void {
    // Arrange
    Config::set('sequencer.primary_key_type', 'uuid');

    // Act
    $result = PrimaryKeyGenerator::generate();

    // Assert
    expect($result->value)->not->toBeNull();
    expect(Str::isUuid($result->value))->toBeTrue();
    expect($result->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
})->group('edge-case');
