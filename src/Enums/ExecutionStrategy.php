<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Enums;

/**
 * Defines execution strategies for Sequencer operation processing.
 *
 * Determines how and when operations are discovered and executed. The strategy
 * choice affects deployment workflows and the level of control over operation
 * execution timing.
 *
 * ```php
 * // Configuration example
 * 'strategy' => ExecutionStrategy::Migration->value,
 *
 * // Or via environment variable
 * 'strategy' => env('SEQUENCER_STRATEGY', 'command'),
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum ExecutionStrategy: string
{
    /**
     * Operations execute only via explicit command invocation.
     *
     * Requires running `php artisan sequencer:process` separately from migrations.
     * Provides maximum control over when operations execute, allowing independent
     * scheduling, tag filtering, and isolation options. Best for teams wanting
     * explicit deployment steps and granular control.
     *
     * Typical workflow:
     *   php artisan migrate
     *   php artisan sequencer:process
     */
    case Command = 'command';

    /**
     * Operations execute automatically during `php artisan migrate`.
     *
     * Hooks into Laravel's migration events to execute operations interleaved
     * with migrations based on timestamps. Provides a single-command deployment
     * experience where operations run automatically at the correct point in the
     * migration sequence. Best for teams wanting streamlined deployments.
     *
     * Typical workflow:
     *   php artisan migrate  # Operations run automatically!
     */
    case Migration = 'migration';
}
