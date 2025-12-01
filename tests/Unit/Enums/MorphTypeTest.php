<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\MorphType;

describe('MorphType Enum', function (): void {
    test('has Morph case', function (): void {
        expect(MorphType::String)->toBeInstanceOf(MorphType::class);
        expect(MorphType::String->value)->toBe('string');
    });

    test('has Numeric case', function (): void {
        expect(MorphType::Numeric)->toBeInstanceOf(MorphType::class);
        expect(MorphType::Numeric->value)->toBe('numeric');
    });

    test('has UUID case', function (): void {
        expect(MorphType::UUID)->toBeInstanceOf(MorphType::class);
        expect(MorphType::UUID->value)->toBe('uuid');
    });

    test('has ULID case', function (): void {
        expect(MorphType::ULID)->toBeInstanceOf(MorphType::class);
        expect(MorphType::ULID->value)->toBe('ulid');
    });

    test('can be instantiated from string value', function (): void {
        expect(MorphType::from('string'))->toBe(MorphType::String);
        expect(MorphType::from('numeric'))->toBe(MorphType::Numeric);
        expect(MorphType::from('uuid'))->toBe(MorphType::UUID);
        expect(MorphType::from('ulid'))->toBe(MorphType::ULID);
    });

    test('all cases have string values', function (): void {
        foreach (MorphType::cases() as $case) {
            expect($case->value)->toBeString();
        }
    });

    test('has exactly four cases', function (): void {
        expect(MorphType::cases())->toHaveCount(4);
    });

    test('values are unique', function (): void {
        $values = array_map(fn (MorphType $case) => $case->value, MorphType::cases());

        expect($values)->toBe(array_unique($values));
    });

    test('values are simple identifier strings', function (): void {
        expect(MorphType::String->value)->toBe('string');
        expect(MorphType::Numeric->value)->toBe('numeric');
        expect(MorphType::UUID->value)->toBe('uuid');
        expect(MorphType::ULID->value)->toBe('ulid');
    });
});
