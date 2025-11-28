<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations that execute conditionally based on runtime evaluation.
 *
 * Operations implementing this interface provide a shouldRun() method that Sequencer
 * evaluates immediately before execution. Returning false skips the operation entirely
 * without marking it as failed, allowing dynamic execution decisions based on application
 * state, environment configuration, feature flags, database records, or external system
 * availability.
 *
 * The shouldRun() check executes after dependency resolution but before the operation's
 * handle() method, making it suitable for decisions that require database access, file
 * system checks, or other I/O operations. For simpler skip logic, operations can also
 * throw SkipOperationException from within handle() to skip after execution has begun.
 *
 * Skipped operations are logged and tracked in the execution history but are not counted
 * as failures. This allows for safe conditional deployment logic without compromising
 * deployment success metrics or triggering rollback procedures.
 *
 * Common use cases:
 * - Environment-specific operations (production-only, staging-only)
 * - Feature flag controlled rollouts
 * - Data migrations that verify records need processing
 * - Operations dependent on external service availability
 * - Conditional cache warming based on current cache state
 *
 * ```php
 * final class MigrateUsersToNewSchema implements Operation, ConditionalExecution
 * {
 *     public function shouldRun(): bool
 *     {
 *         return config('features.new_user_schema_enabled')
 *             && User::where('migrated', false)->exists();
 *     }
 *
 *     public function handle(): void
 *     {
 *         User::where('migrated', false)->chunk(100, function ($users) {
 *             // Migration logic guaranteed to have records
 *         });
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface ConditionalExecution extends Operation
{
    /**
     * Determine whether this operation should execute based on runtime conditions.
     *
     * Sequencer evaluates this method immediately before executing handle(), after
     * dependency resolution completes. Returning false skips the operation without
     * marking it as failed or triggering rollback procedures. The skip event is
     * logged and tracked in the execution history for audit purposes.
     *
     * This method can safely perform I/O operations, database queries, file system
     * checks, or external API calls to make skip decisions. For skip logic that
     * requires locks or state discovered during execution, throw SkipOperationException
     * from handle() instead.
     *
     * @return bool True to proceed with execution, false to skip this operation
     */
    public function shouldRun(): bool;
}
