<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Marker interface for operations that can execute multiple times safely.
 *
 * Operations implementing this interface guarantee idempotent behavior: repeated execution
 * produces the same result without unintended side effects, data corruption, or duplicate
 * records. This property is essential for operations that may retry after failures, run
 * multiple times during testing and debugging, or execute in environments where exactly-once
 * semantics cannot be guaranteed.
 *
 * Idempotent operations typically employ these patterns:
 * - Check-then-act: Verify state before making changes
 * - Upsert: Use firstOrCreate, updateOrCreate, or database upserts
 * - Unique constraints: Rely on database constraints to prevent duplicates
 * - State-based: Make decisions based on current state, not execution count
 * - Natural idempotency: Operations that are naturally idempotent (setting values)
 *
 * While Sequencer tracks all operations to prevent duplicate execution during normal
 * deployment flow, marking operations as idempotent provides additional safety for manual
 * re-runs, testing scenarios, and error recovery situations where the same operation might
 * execute multiple times.
 *
 * The Idempotent marker does not change Sequencer's tracking behavior - all operations are
 * tracked regardless of this interface. It serves as documentation and allows for special
 * handling in testing, manual execution, or error recovery scenarios.
 *
 * Common use cases:
 * - Creating records with unique constraints (email, external ID)
 * - Setting configuration or feature flag values
 * - Upserting reference data or seed records
 * - Creating database indexes (IF NOT EXISTS)
 * - Assigning roles or permissions (state-based)
 * - Synchronizing external system state
 *
 * ```php
 * final class CreateDefaultAdminUser implements Operation, Idempotent
 * {
 *     public function handle(): void
 *     {
 *         // Safe to run multiple times - unique email prevents duplicates
 *         User::firstOrCreate(
 *             ['email' => 'admin@example.com'],
 *             ['name' => 'Admin', 'role' => 'admin']
 *         );
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Idempotent extends Operation
{
    // Marker interface - no additional methods required
}
