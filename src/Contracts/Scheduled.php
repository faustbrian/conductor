<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

use DateTimeInterface;

/**
 * Contract for operations that execute at a specific scheduled time.
 *
 * Operations implementing this interface define a target execution time that Sequencer
 * respects when processing the deployment queue. The orchestrator delays execution until
 * the specified moment, enabling precise timing control for maintenance windows, off-peak
 * processing, coordinated rollouts, and time-sensitive data migrations.
 *
 * Scheduled operations integrate with Sequencer's normal execution flow while adding
 * temporal constraints. The orchestrator evaluates the scheduled time immediately before
 * execution, delaying the operation if the target time is in the future or proceeding
 * immediately if the time has already passed. This supports both advance scheduling and
 * retroactive execution of missed schedules.
 *
 * The scheduled time applies only to the initial execution attempt. Retry attempts for
 * failed scheduled operations execute immediately according to the retry backoff strategy,
 * not the original schedule. This prevents cascading delays when operations fail close
 * to their scheduled time.
 *
 * Scheduled operations are particularly valuable in multi-region deployments where database
 * changes must coordinate across time zones, during planned maintenance windows that require
 * user notification, or for operations that depend on external systems with known availability
 * windows.
 *
 * Common use cases:
 * - Maintenance operations during low-traffic hours
 * - Data migrations requiring advance user notification
 * - Operations coordinating with external system maintenance
 * - Multi-region rollouts respecting business hours
 * - Database changes during backup windows
 * - Cache warming before anticipated traffic spikes
 *
 * ```php
 * final class MigrateLegacyUserData implements Operation, Scheduled
 * {
 *     public function executeAt(): DateTimeInterface
 *     {
 *         // Execute at 2am server time to minimize user impact
 *         return now()->setTime(2, 0);
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Migration runs during low-traffic window
 *         DB::table('legacy_users')->chunk(1000, function ($users) {
 *             // Process user migration
 *         });
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Scheduled
{
    /**
     * Get the target execution time for this operation.
     *
     * Defines the exact moment when the operation should execute. Sequencer evaluates
     * this time immediately before execution and delays the operation if the target is
     * in the future. If the returned time is in the past, the operation executes
     * immediately without delay.
     *
     * The scheduled time applies only to the initial execution attempt. Retries for
     * failed operations execute according to retry backoff strategy, not the original
     * schedule. This prevents cascading delays when operations fail near their scheduled
     * execution time.
     *
     * Return times should consider server timezone and deployment environment. Use
     * Laravel's now() helper with timezone conversions for cross-region deployments,
     * or Carbon's timezone methods to ensure consistent execution timing.
     *
     * @return DateTimeInterface Target execution time in server timezone
     */
    public function executeAt(): DateTimeInterface;
}
