<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

use Throwable;

/**
 * Contract for operations that support rollback and recovery functionality.
 *
 * Operations implementing this interface provide a rollback() method that Sequencer
 * invokes to undo changes when execution fails or when manually reverting a batch
 * of operations. This enables recovery from partial failures and maintains data
 * consistency during deployment issues.
 *
 * The rollback method should reverse all side effects created by handle(), including
 * database changes, file modifications, cache updates, and external API calls. Design
 * rollbacks to be safe even if handle() completed partially or failed midway through
 * execution.
 *
 * Rollback operations are typically executed in reverse chronological order when
 * reverting a deployment or recovering from a failed orchestration sequence. Consider
 * implementing idempotent rollback logic to handle repeated rollback attempts safely.
 *
 * ```php
 * final class MigrateUserDataToNewSchema implements Rollbackable
 * {
 *     public function handle(): void
 *     {
 *         DB::table('users_new')
 *             ->insert(DB::table('users_old')->get()->toArray());
 *     }
 *
 *     public function rollback(): void
 *     {
 *         DB::table('users_new')->truncate();
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Rollbackable extends Operation
{
    /**
     * Rollback the operation by undoing changes made during handle() execution.
     *
     * Invoked when the operation fails, when a subsequent operation fails requiring
     * cascade rollback, or when manually reverting a deployment batch. Should reverse
     * all side effects including database modifications, file changes, cache updates,
     * and external integrations created by the handle() method.
     *
     * @throws Throwable When rollback encounters an unrecoverable error that prevents
     *                   proper cleanup and should be logged for manual intervention
     */
    public function rollback(): void;
}
