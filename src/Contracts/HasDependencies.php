<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations that declare explicit execution dependencies.
 *
 * Operations implementing this interface specify other operations, migrations, or tasks
 * that must complete successfully before this operation executes. Sequencer validates and
 * enforces these dependencies regardless of timestamp ordering, providing fine-grained
 * control over execution sequences in complex dependency graphs.
 *
 * Sequencer's dependency resolver performs topological sorting to determine the correct
 * execution order, validates that all dependencies exist, and detects circular dependencies
 * at resolution time to prevent deadlocks. Operations are guaranteed to execute only after
 * all declared dependencies have completed successfully.
 *
 * Dependencies are identified by fully-qualified class names for operations or migration
 * file names for database migrations. Skipped operations (via ConditionalExecution or
 * SkipOperationException) still satisfy dependencies as long as they don't fail.
 *
 * Common use cases:
 * - Operations requiring specific database schema changes
 * - Data transformations that depend on seed data
 * - Multi-step migrations where each step builds on the previous
 * - Operations that require configuration changes from earlier operations
 * - External integrations that depend on local data preparation
 *
 * ```php
 * final class AssignDefaultRolesToUsers implements Operation, HasDependencies
 * {
 *     public function dependsOn(): array
 *     {
 *         return [
 *             '2024_01_15_000001_create_roles_table',
 *             SeedDefaultRoles::class,
 *         ];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Roles table schema and seed data guaranteed to exist
 *         $defaultRole = Role::where('name', 'user')->first();
 *         User::whereNull('role_id')->update(['role_id' => $defaultRole->id]);
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface HasDependencies extends Operation
{
    /**
     * Get the list of dependencies that must complete before this operation executes.
     *
     * Returns an array of dependency identifiers that Sequencer uses to build the execution
     * graph and determine operation ordering. Each identifier can be a fully-qualified class
     * name for operations or a migration file name (with or without timestamp prefix).
     *
     * Sequencer performs topological sorting on the dependency graph and validates that:
     * - All declared dependencies exist in the operation set
     * - No circular dependencies are present
     * - All dependencies complete successfully before this operation runs
     *
     * Skipped operations (via ConditionalExecution) satisfy dependencies without blocking
     * dependent operations. Failed dependencies prevent this operation from executing.
     *
     * @return list<string> Array of fully-qualified class names or migration file names
     */
    public function dependsOn(): array;
}
