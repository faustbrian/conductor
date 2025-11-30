<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\ExecutionStrategy;

describe('ExecutionStrategy Enum', function (): void {
    test('has command case with correct value', function (): void {
        expect(ExecutionStrategy::Command->value)->toBe('command');
    });

    test('has migration case with correct value', function (): void {
        expect(ExecutionStrategy::Migration->value)->toBe('migration');
    });

    test('can be created from valid string value', function (): void {
        expect(ExecutionStrategy::from('command'))->toBe(ExecutionStrategy::Command)
            ->and(ExecutionStrategy::from('migration'))->toBe(ExecutionStrategy::Migration);
    });

    test('tryFrom returns null for invalid value', function (): void {
        expect(ExecutionStrategy::tryFrom('invalid'))->toBeNull()
            ->and(ExecutionStrategy::tryFrom('event'))->toBeNull();
    });

    test('has exactly two cases', function (): void {
        expect(ExecutionStrategy::cases())->toHaveCount(2);
    });
});
