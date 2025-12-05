<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for execution guards that control when operations can execute.
 *
 * Guards provide a mechanism to restrict operation execution based on runtime
 * conditions like hostname, IP address, environment, or custom business logic.
 * Unlike execution strategies (which control HOW operations execute), guards
 * control WHETHER operations should execute at all.
 *
 * Guards are evaluated before any operations run. If any guard returns false
 * from shouldExecute(), the entire execution is blocked with an appropriate
 * message.
 *
 * Example use cases:
 * - Restrict operations to specific servers (e.g., only run on 'hel2', not 'us3')
 * - Limit execution to specific IP ranges for security
 * - Block execution during maintenance windows
 * - Require specific infrastructure conditions before proceeding
 *
 * ```php
 * class MaintenanceWindowGuard implements ExecutionGuard
 * {
 *     public function shouldExecute(): bool
 *     {
 *         return !$this->isMaintenanceWindow();
 *     }
 *
 *     public function reason(): string
 *     {
 *         return 'Operations blocked during maintenance window';
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface ExecutionGuard
{
    /**
     * Determine if operations should be allowed to execute.
     *
     * Called before any operations are processed. When this returns false,
     * no operations will execute and the reason() message will be displayed.
     *
     * @return bool True if operations should proceed, false to block execution
     */
    public function shouldExecute(): bool;

    /**
     * Get a human-readable explanation for why execution was blocked.
     *
     * Only called when shouldExecute() returns false. The message should
     * clearly explain why execution was prevented and ideally suggest
     * how to resolve the condition.
     *
     * @return string Explanation message for display to users and logs
     */
    public function reason(): string;

    /**
     * Get the guard identifier for logging and debugging.
     *
     * @return string Human-readable guard name (e.g., 'hostname', 'ip_address')
     */
    public function name(): string;
}
