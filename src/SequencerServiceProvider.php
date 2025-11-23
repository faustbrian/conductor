<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer;

use Cline\Sequencer\Commands\ProcessCommand;
use Cline\Sequencer\Commands\ProcessScheduledCommand;
use Cline\Sequencer\Commands\StatusCommand;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Database\Models\Operation;
use Cline\Sequencer\Observers\SequencerObserver;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Override;

use function config_path;
use function database_path;

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
final class SequencerServiceProvider extends ServiceProvider
{
    /**
     * Register package services into the Laravel container.
     *
     * Merges package configuration with application config, registers the default
     * SequentialOrchestrator as a singleton, binds the Orchestrator interface to
     * either the configured custom orchestrator or the default implementation, and
     * registers the SequencerManager facade as a singleton for programmatic access.
     */
    #[Override()]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/sequencer.php',
            'sequencer',
        );

        $this->app->singleton(SequentialOrchestrator::class);

        // Bind Orchestrator interface to configured orchestrator or default
        $this->app->singleton(Orchestrator::class, fn () => $this->app->make(SequentialOrchestrator::class));

        $this->app->singleton(SequencerManager::class);
    }

    /**
     * Bootstrap package services after container registration.
     *
     * Called after all service providers have been registered, ensuring safe access
     * to all container bindings. Initializes publishable resources for configuration
     * and migrations, registers Artisan commands for operation management, and
     * attaches model observers for tracking operation lifecycle events.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerObservers();
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

    /**
     * Register publishable package resources for console environments.
     *
     * Makes configuration files, database migrations, and operation stub templates
     * available for publishing to the application via `php artisan vendor:publish`.
     * Only registers publishers when running in console mode to avoid unnecessary
     * overhead in HTTP and queue worker contexts.
     *
     * Publishable resources:
     * - sequencer-config: Main configuration file to config/sequencer.php
     * - sequencer-migrations: Database migration with timestamped filename
     * - sequencer-stubs: Operation stub templates to database/operations directory
     */
    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sequencer.php' => config_path('sequencer.php'),
            ], 'sequencer-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_sequencer_tables.php' => database_path('migrations/'.Date::now()->format('Y_m_d_His').'_create_sequencer_tables.php'),
            ], 'sequencer-migrations');

            $this->publishes([
                __DIR__.'/../stubs' => database_path('operations'),
            ], 'sequencer-stubs');
        }
    }

    /**
     * Register package Artisan commands for console environments.
     *
     * Makes Sequencer management commands available to the application when running
     * in console mode. Provides the primary CLI interface for discovering, executing,
     * scheduling, and monitoring operations through the orchestration system.
     *
     * Registered commands:
     * - ProcessCommand: Execute pending operations via sequencer:process
     * - ProcessScheduledCommand: Execute scheduled operations via sequencer:process-scheduled
     * - StatusCommand: View operation execution status via sequencer:status
     */
    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessCommand::class,
                ProcessScheduledCommand::class,
                StatusCommand::class,
            ]);
        }
    }
}
