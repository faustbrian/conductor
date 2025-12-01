<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Strategies;

use Cline\Sequencer\Contracts\ExecutionStrategy;

/**
 * Default strategy requiring explicit command invocation.
 *
 * Operations only execute when users run sequencer:process or sequencer:execute.
 * Provides maximum control and explicit behavior - nothing happens automatically.
 * This is the default strategy for backward compatibility and predictable deployments.
 *
 * Typical workflow:
 * ```bash
 * php artisan migrate && php artisan sequencer:process
 * ```
 *
 * Or combined in deployment scripts:
 * ```bash
 * php artisan migrate
 * php artisan sequencer:process --isolated
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class CommandStrategy implements ExecutionStrategy
{
    /**
     * Register the strategy with Laravel.
     *
     * No registration needed for command strategy - commands are always available
     * and no event listeners are required.
     */
    public function register(): void
    {
        // No registration needed - commands are always available
    }

    /**
     * Check if this strategy handles operation execution automatically.
     *
     * Command strategy requires explicit invocation, so this returns false.
     *
     * @return bool Always false - operations require explicit command execution
     */
    public function isAutomatic(): bool
    {
        return false;
    }

    /**
     * Get the strategy identifier for logging and debugging.
     *
     * @return string Returns 'command'
     */
    public function name(): string
    {
        return 'command';
    }
}
