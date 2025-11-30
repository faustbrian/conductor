<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations that execute only in specific application environments.
 *
 * Operations implementing this interface define target environments where execution should
 * proceed. Sequencer evaluates the current application environment against the provided list
 * immediately before execution. Operations skip automatically when the current environment
 * doesn't match, without marking them as failed or triggering rollback procedures.
 *
 * Environment checks execute after dependency resolution but before the operation's handle()
 * method, making them suitable for environment-based deployment strategies. Unlike ConditionalExecution
 * which requires runtime I/O for skip decisions, environment checks use Laravel's cached configuration
 * for instant evaluation with zero overhead.
 *
 * Skipped operations are logged and tracked in the execution history but are not counted
 * as failures. This enables safe environment-specific deployment logic without compromising
 * deployment success metrics or triggering rollback procedures.
 *
 * Common use cases:
 * - Production-only data migrations or cache warming
 * - Staging/testing-specific seed operations
 * - Development-only debug tooling installation
 * - Local-only database resets or test data population
 * - Environment-specific feature flag or configuration changes
 *
 * ```php
 * final class WarmProductionCache implements Operation, EnvironmentSpecific
 * {
 *     public function environments(): array
 *     {
 *         return ['production', 'staging'];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Cache warming only runs in production/staging
 *         Cache::rememberForever('critical_data', fn () => $this->loadCriticalData());
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface EnvironmentSpecific extends Operation
{
    /**
     * Get the list of environments where this operation should execute.
     *
     * Sequencer evaluates this list immediately before executing handle(), after
     * dependency resolution completes. If the current application environment
     * (determined by App::environment()) doesn't match any entry in this array,
     * the operation skips without marking it as failed or triggering rollback.
     *
     * Environment names must match Laravel's configured environment exactly. Common
     * values include 'production', 'staging', 'testing', 'local'. The check is
     * case-sensitive and uses strict string comparison.
     *
     * Empty arrays are treated as "run in no environments" and will always skip.
     * To run in all environments, don't implement this interface.
     *
     * @return list<string> Array of environment names where execution should proceed
     */
    public function environments(): array;
}
