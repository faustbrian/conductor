<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Base contract for all Sequencer operations.
 *
 * Operations represent discrete units of business logic that execute alongside database
 * migrations during deployment orchestration. While migrations handle database schema
 * changes, operations perform data transformations, external system integrations, cache
 * management, notification distribution, and other deployment-related tasks that extend
 * beyond pure schema modifications.
 *
 * Sequencer executes operations in chronological order based on their timestamp prefixes,
 * similar to how Laravel processes migrations. Each operation runs exactly once per
 * environment, with execution state tracked in the database to prevent duplicate runs
 * and maintain a complete audit trail of deployment activities.
 *
 * Operations inherit the handle() method from Processable, which contains the main
 * execution logic. The operation pattern provides a structured approach to deployment
 * tasks while maintaining the same timestamp-based ordering and tracking semantics as
 * database migrations.
 *
 * Execution control and behavior modification:
 * Operations can implement additional interfaces to control their execution behavior:
 * - Asynchronous: Dispatch to queue workers for background execution
 * - ConditionalExecution: Skip based on runtime state evaluation
 * - HasDependencies: Enforce explicit execution order regardless of timestamps
 * - HasLifecycleHooks: Add before/after/failed callbacks
 * - HasMiddleware: Apply middleware layers (rate limiting, overlap prevention)
 * - Idempotent: Safe for repeated execution without side effects
 * - Rollbackable: Support undo functionality for operation reversal
 * - Retryable: Configure retry behavior for transient failures
 * - WithinTransaction: Execute atomically within database transaction
 *
 * Runtime skip handling:
 * Operations can throw SkipOperationException from within handle() to skip execution
 * after it has begun. This is useful when skip decisions require locks, I/O operations,
 * or state discovered during execution. For simpler skip logic based on configuration
 * or environment state, implement ConditionalExecution instead.
 *
 * Common use cases:
 * - Data migrations and transformations
 * - External API synchronization
 * - Cache warming and invalidation
 * - Search index updates
 * - Notification distribution
 * - Feature flag configuration
 * - Seed data creation
 * - File system operations
 *
 * ```php
 * final class NotifyUsersOfSystemUpgrade implements Operation
 * {
 *     public function handle(): void
 *     {
 *         User::where('active', true)
 *             ->each(fn ($user) => $user->notify(new SystemUpgradeNotification()));
 *     }
 * }
 * ```
 *
 * @api
 *
 * @see Processable
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Operation extends Processable
{
    // Operations inherit handle() from Processable
}
