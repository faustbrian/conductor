<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\ExecutionStrategy;
use Cline\Sequencer\Strategies\CommandStrategy;

describe('CommandStrategy', function (): void {
    test('implements ExecutionStrategy interface', function (): void {
        $strategy = new CommandStrategy();

        expect($strategy)->toBeInstanceOf(ExecutionStrategy::class);
    });

    test('register method exists and is callable', function (): void {
        $strategy = new CommandStrategy();

        expect(fn () => $strategy->register())->not->toThrow(Throwable::class);
    });

    test('isAutomatic returns false', function (): void {
        $strategy = new CommandStrategy();

        expect($strategy->isAutomatic())->toBeFalse();
    });

    test('name returns command string', function (): void {
        $strategy = new CommandStrategy();

        expect($strategy->name())->toBe('command');
    });

    test('is immutable', function (): void {
        $reflection = new ReflectionClass(CommandStrategy::class);

        expect($reflection->isReadOnly())->toBeTrue();
    });
});
