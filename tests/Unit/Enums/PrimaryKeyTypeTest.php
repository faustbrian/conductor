<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\PrimaryKeyType;

describe('PrimaryKeyType Enum', function (): void {
    test('has ID case', function (): void {
        expect(PrimaryKeyType::ID)->toBeInstanceOf(PrimaryKeyType::class);
        expect(PrimaryKeyType::ID->value)->toBe('id');
    });

    test('has ULID case', function (): void {
        expect(PrimaryKeyType::ULID)->toBeInstanceOf(PrimaryKeyType::class);
        expect(PrimaryKeyType::ULID->value)->toBe('ulid');
    });

    test('has UUID case', function (): void {
        expect(PrimaryKeyType::UUID)->toBeInstanceOf(PrimaryKeyType::class);
        expect(PrimaryKeyType::UUID->value)->toBe('uuid');
    });

    test('can be instantiated from string value', function (): void {
        expect(PrimaryKeyType::from('id'))->toBe(PrimaryKeyType::ID);
        expect(PrimaryKeyType::from('ulid'))->toBe(PrimaryKeyType::ULID);
        expect(PrimaryKeyType::from('uuid'))->toBe(PrimaryKeyType::UUID);
    });

    test('all cases have string values', function (): void {
        foreach (PrimaryKeyType::cases() as $case) {
            expect($case->value)->toBeString();
        }
    });

    test('has exactly three cases', function (): void {
        expect(PrimaryKeyType::cases())->toHaveCount(3);
    });

    test('values are unique', function (): void {
        $values = array_map(fn (PrimaryKeyType $case) => $case->value, PrimaryKeyType::cases());

        expect($values)->toBe(array_unique($values));
    });
});
