<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Marker interface for operations that require database transaction boundaries.
 *
 * Operations implementing this interface execute within an automatic database transaction
 * that commits on success and rolls back on any exception. This guarantees atomic execution
 * for operations performing multiple related database modifications that must succeed or
 * fail as a single unit.
 *
 * This interface overrides the global sequencer.execution.auto_transaction configuration
 * setting for individual operations, allowing fine-grained control over transactional
 * behavior. Use this when specific operations require ACID guarantees even if the global
 * setting disables automatic transactions.
 *
 * The transaction scope includes only the operation's handle() method execution. If the
 * operation dispatches jobs or triggers events, those side effects occur outside the
 * transaction boundary unless explicitly wrapped.
 *
 * ```php
 * final class TransferUserSubscriptions implements WithinTransaction
 * {
 *     public function handle(): void
 *     {
 *         // All database operations execute atomically
 *         DB::table('old_subscriptions')->each(function ($sub) {
 *             DB::table('new_subscriptions')->insert([...]);
 *             DB::table('old_subscriptions')->where('id', $sub->id)->delete();
 *         });
 *         // Automatic rollback if any step fails
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface WithinTransaction extends Operation
{
    // Marker interface - no additional methods required
}
