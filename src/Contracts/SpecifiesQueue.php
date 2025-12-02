<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations that specify which queue they should be dispatched to.
 *
 * Operations implementing this interface can define their own queue routing,
 * overriding the global queue configuration. This enables priority-based execution,
 * resource isolation, and workload distribution across specialized queue workers.
 *
 * The queue name returned by queue() is used when dispatching the operation to
 * Laravel's queue system. Queue workers must be configured to process the
 * specified queue for the operation to execute.
 *
 * Common queue strategies:
 * - Priority-based: 'high-priority', 'standard', 'low-priority'
 * - Resource-based: 'cpu-intensive', 'memory-intensive', 'io-bound'
 * - Domain-based: 'billing', 'notifications', 'reporting'
 * - SLA-based: 'immediate', 'within-5-minutes', 'background'
 *
 * ```php
 * final class ProcessLargeReport implements Operation, Asynchronous, SpecifiesQueue
 * {
 *     public function queue(): string
 *     {
 *         return 'high-priority';
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Report generation logic
 *     }
 * }
 * ```
 *
 * Priority order (highest to lowest):
 * 1. Command-line --queue flag
 * 2. Operation's queue() method (this interface)
 * 3. Global configuration (config/sequencer.php)
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface SpecifiesQueue
{
    /**
     * Get the queue name where this operation should be dispatched.
     *
     * Returns the queue name that Laravel's queue system will use when
     * dispatching this operation for background execution. Must match a
     * queue that workers are configured to process.
     *
     * Use descriptive queue names that indicate priority, resource requirements,
     * or business domain. Coordinate with your queue worker configuration to
     * ensure operations are processed by appropriate workers.
     *
     * @return string Queue name for operation dispatch (e.g., 'high-priority', 'default')
     */
    public function queue(): string;
}
