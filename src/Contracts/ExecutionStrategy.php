<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Defines how Sequencer discovers and executes operations.
 *
 * Strategies determine when operations are discovered, validated, and executed.
 * The default CommandStrategy requires explicit invocation via Artisan commands,
 * while MigrationStrategy hooks into Laravel's migration events for automatic execution.
 *
 * ```php
 * // Command strategy (default) - explicit invocation
 * php artisan migrate && php artisan sequencer:process
 *
 * // Event strategy - automatic during migrations
 * php artisan migrate  // Operations run automatically!
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface ExecutionStrategy
{
    /**
     * Register the strategy with Laravel.
     *
     * Called during service provider boot. Strategies should register any
     * event listeners, command modifications, or hooks needed for their
     * execution model.
     */
    public function register(): void;

    /**
     * Check if this strategy handles operation execution automatically.
     *
     * When true, the sequencer:process command will warn users that operations
     * are handled automatically and the command may be redundant.
     *
     * @return bool True if operations execute automatically without explicit commands
     */
    public function isAutomatic(): bool;

    /**
     * Get the strategy identifier for logging and debugging.
     *
     * @return string Human-readable strategy name (e.g., 'command', 'migration')
     */
    public function name(): string;
}
