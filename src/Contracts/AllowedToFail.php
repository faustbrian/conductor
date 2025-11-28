<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Marker interface for operations that can fail without affecting execution flow.
 *
 * Operations implementing this interface signal to Sequencer that failures are acceptable
 * and should not halt batch processing, trigger rollbacks of sibling operations, or mark
 * the deployment as failed. This is essential for non-critical operations where eventual
 * consistency is acceptable or manual intervention is preferred over blocking deployments.
 *
 * When an operation marked with this interface fails:
 * - The exception is logged but not propagated
 * - Subsequent operations in the batch continue executing
 * - The overall batch completes successfully
 * - Rollback hooks are not triggered for other operations
 *
 * Common use cases:
 * - Notification delivery where user experience is unaffected by failure
 * - Cache warming that can be regenerated on first access
 * - Search index updates where eventual consistency is acceptable
 * - Analytics tracking that can tolerate data gaps
 * - Optional data migrations that can be retried manually
 *
 * ```php
 * final class SendWelcomeEmailsToNewUsers implements Operation, AllowedToFail
 * {
 *     public function handle(): void
 *     {
 *         // Email failures won't prevent deployment from succeeding
 *         User::whereNull('welcomed_at')->each(fn ($user) => Mail::to($user)->send(new WelcomeEmail()));
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface AllowedToFail
{
    // Marker interface - no methods required
}
