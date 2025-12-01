<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\ExecutionStrategy;
use Cline\Sequencer\Strategies\MigrationStrategy;

describe('MigrationStrategy', function (): void {
    test('implements ExecutionStrategy interface', function (): void {
        $strategy = app(MigrationStrategy::class);

        expect($strategy)->toBeInstanceOf(ExecutionStrategy::class);
    });

    test('isAutomatic returns true', function (): void {
        $strategy = app(MigrationStrategy::class);

        expect($strategy->isAutomatic())->toBeTrue();
    });

    test('name returns migration string', function (): void {
        $strategy = app(MigrationStrategy::class);

        expect($strategy->name())->toBe('migration');
    });

    test('can be resolved from container', function (): void {
        $strategy = app(MigrationStrategy::class);

        expect($strategy)->toBeInstanceOf(MigrationStrategy::class)
            ->toBeInstanceOf(ExecutionStrategy::class);
    });

    test('register method does not throw', function (): void {
        $strategy = app(MigrationStrategy::class);

        expect(fn () => $strategy->register())->not->toThrow(Throwable::class);
    });
});
