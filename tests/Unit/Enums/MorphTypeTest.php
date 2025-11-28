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
        expect(MorphType::Morph)->toBeInstanceOf(MorphType::class);
        expect(MorphType::Morph->value)->toBe('morph');
    });

    test('has Numeric case', function (): void {
        expect(MorphType::Numeric)->toBeInstanceOf(MorphType::class);
        expect(MorphType::Numeric->value)->toBe('numericMorph');
    });

    test('has UUID case', function (): void {
        expect(MorphType::UUID)->toBeInstanceOf(MorphType::class);
        expect(MorphType::UUID->value)->toBe('uuidMorph');
    });

    test('has ULID case', function (): void {
        expect(MorphType::ULID)->toBeInstanceOf(MorphType::class);
        expect(MorphType::ULID->value)->toBe('ulidMorph');
    });

    test('can be instantiated from string value', function (): void {
        expect(MorphType::from('morph'))->toBe(MorphType::Morph);
        expect(MorphType::from('numericMorph'))->toBe(MorphType::Numeric);
        expect(MorphType::from('uuidMorph'))->toBe(MorphType::UUID);
        expect(MorphType::from('ulidMorph'))->toBe(MorphType::ULID);
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

    test('values follow Laravel morph naming convention', function (): void {
        expect(MorphType::Morph->value)->toEndWith('morph');
        expect(MorphType::Numeric->value)->toEndWith('Morph');
        expect(MorphType::UUID->value)->toEndWith('Morph');
        expect(MorphType::ULID->value)->toEndWith('Morph');
    });
});
