<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Contracts\ExecutionStrategy;
use Cline\Sequencer\Strategies\CommandStrategy;
use Cline\Sequencer\Strategies\MigrationStrategy;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\NoPendingMigrations;

describe('ExecutionStrategy Configuration', function (): void {
    test('default strategy is command', function (): void {
        config(['sequencer.strategy' => 'command']);

        // Re-resolve to get fresh instance
        $this->app->forgetInstance(ExecutionStrategy::class);

        $strategy = resolve(ExecutionStrategy::class);

        expect($strategy)->toBeInstanceOf(CommandStrategy::class)
            ->and($strategy->name())->toBe('command')
            ->and($strategy->isAutomatic())->toBeFalse();
    });

    test('can configure migration strategy', function (): void {
        config(['sequencer.strategy' => 'migration']);

        // Re-resolve to get fresh instance
        $this->app->forgetInstance(ExecutionStrategy::class);

        $strategy = resolve(ExecutionStrategy::class);

        expect($strategy)->toBeInstanceOf(MigrationStrategy::class)
            ->and($strategy->name())->toBe('migration')
            ->and($strategy->isAutomatic())->toBeTrue();
    });

    test('unknown strategy defaults to command', function (): void {
        config(['sequencer.strategy' => 'unknown']);

        // Re-resolve to get fresh instance
        $this->app->forgetInstance(ExecutionStrategy::class);

        $strategy = resolve(ExecutionStrategy::class);

        expect($strategy)->toBeInstanceOf(CommandStrategy::class);
    });
});

describe('CommandStrategy', function (): void {
    test('register does nothing', function (): void {
        $strategy = new CommandStrategy();

        // Should not throw
        $strategy->register();

        expect(true)->toBeTrue();
    });

    test('is not automatic', function (): void {
        $strategy = new CommandStrategy();

        expect($strategy->isAutomatic())->toBeFalse();
    });

    test('name returns command', function (): void {
        $strategy = new CommandStrategy();

        expect($strategy->name())->toBe('command');
    });
});

describe('MigrationStrategy', function (): void {
    test('is automatic', function (): void {
        $strategy = resolve(MigrationStrategy::class);

        expect($strategy->isAutomatic())->toBeTrue();
    });

    test('name returns migration', function (): void {
        $strategy = resolve(MigrationStrategy::class);

        expect($strategy->name())->toBe('migration');
    });

    test('registers event listeners when booted', function (): void {
        config(['sequencer.strategy' => 'migration']);

        // Re-resolve to get fresh instance
        $this->app->forgetInstance(ExecutionStrategy::class);

        $strategy = resolve(MigrationStrategy::class);
        $strategy->register();

        // Check that listeners are registered
        $dispatcher = resolve(Dispatcher::class);

        expect($dispatcher->hasListeners(CommandStarting::class))->toBeTrue()
            ->and($dispatcher->hasListeners(CommandFinished::class))->toBeTrue()
            ->and($dispatcher->hasListeners(MigrationsStarted::class))->toBeTrue()
            ->and($dispatcher->hasListeners(MigrationEnded::class))->toBeTrue()
            ->and($dispatcher->hasListeners(MigrationsEnded::class))->toBeTrue()
            ->and($dispatcher->hasListeners(NoPendingMigrations::class))->toBeTrue();
    });
});
