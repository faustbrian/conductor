<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer;

use Cline\Sequencer\Commands\ExecuteCommand;
use Cline\Sequencer\Commands\MakeOperationCommand;
use Cline\Sequencer\Commands\MigrateCommand;
use Cline\Sequencer\Commands\ModeCommand;
use Cline\Sequencer\Commands\ProcessCommand;
use Cline\Sequencer\Commands\ProcessScheduledCommand;
use Cline\Sequencer\Commands\StatusCommand;
use Cline\Sequencer\Contracts\ExecutionStrategy as ExecutionStrategyContract;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Enums\ExecutionStrategy;
use Cline\Sequencer\Observers\SequencerObserver;
use Cline\Sequencer\Strategies\CommandStrategy;
use Cline\Sequencer\Strategies\MigrationStrategy;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

use function config;

/**
 * Laravel service provider for the Sequencer orchestration package.
 *
 * Bootstraps the Sequencer package by registering core services into the container,
 * binding orchestrator implementations, publishing configuration and migrations,
 * registering Artisan commands, and attaching model observers for operation lifecycle
 * tracking. Provides the foundation for operation-based workflow orchestration.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SequencerServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package.
     *
     * Defines package configuration including publishable config, migrations,
     * and Artisan commands for operation management.
     *
     * @param Package $package The package instance to configure
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('sequencer')
            ->hasConfigFile()
            ->hasMigration('create_sequencer_tables')
            ->hasCommands([
                ExecuteCommand::class,
                MakeOperationCommand::class,
                ModeCommand::class,
                ProcessCommand::class,
                ProcessScheduledCommand::class,
                StatusCommand::class,
                MigrateCommand::class,
            ]);
    }

    /**
     * Register package services into the Laravel container.
     *
     * Registers the default SequentialOrchestrator as a singleton, binds the
     * Orchestrator interface to either the configured custom orchestrator or
     * the default implementation, and registers the SequencerManager facade
     * as a singleton for programmatic access.
     */
    #[Override()]
    public function registeringPackage(): void
    {
        $this->app->singleton(SequentialOrchestrator::class);

        // Bind Orchestrator interface to configured orchestrator or default
        $this->app->singleton(Orchestrator::class, fn () => $this->app->make(SequentialOrchestrator::class));

        $this->app->singleton(SequencerManager::class);

        $this->registerStrategies();
    }

    /**
     * Bootstrap package services after container registration.
     *
     * Called after all service providers have been registered, ensuring safe access
     * to all container bindings. Attaches model observers for tracking operation
     * lifecycle events and boots the configured execution strategy.
     */
    #[Override()]
    public function bootingPackage(): void
    {
        $this->registerObservers();
        $this->bootStrategy();
    }

    /**
     * Register execution strategies into the container.
     *
     * Registers both CommandStrategy and MigrationStrategy as singletons,
     * then binds the ExecutionStrategy interface to the configured
     * strategy based on the 'sequencer.strategy' configuration value.
     * Uses the ExecutionStrategy enum for type-safe strategy resolution.
     */
    private function registerStrategies(): void
    {
        // Register both strategies as singletons
        $this->app->singleton(CommandStrategy::class);
        $this->app->singleton(MigrationStrategy::class);

        // Bind interface to configured strategy
        $this->app->singleton(function (Application $app): ExecutionStrategyContract {
            /** @var string $strategyValue */
            $strategyValue = config('sequencer.strategy', ExecutionStrategy::Command->value);

            $strategy = ExecutionStrategy::tryFrom($strategyValue) ?? ExecutionStrategy::Command;

            return match ($strategy) {
                ExecutionStrategy::Migration => $app->make(MigrationStrategy::class),
                ExecutionStrategy::Command => $app->make(CommandStrategy::class),
            };
        });
    }

    /**
     * Boot the configured execution strategy.
     *
     * Resolves the ExecutionStrategy from the container and calls its
     * register() method to set up any event listeners or hooks required
     * for the strategy's execution model.
     */
    private function bootStrategy(): void
    {
        /** @var ExecutionStrategyContract $strategy */
        $strategy = $this->app->make(ExecutionStrategyContract::class);

        $strategy->register();
    }

    /**
     * Register model observers for operation lifecycle event tracking.
     *
     * Attaches the SequencerObserver to the Operation model to monitor Eloquent
     * events such as creation, updates, and deletion. Enables automatic state
     * management, audit logging, and side effect handling during operation
     * lifecycle transitions.
     */
    private function registerObservers(): void
    {
        Operation::observe(SequencerObserver::class);
    }
}
