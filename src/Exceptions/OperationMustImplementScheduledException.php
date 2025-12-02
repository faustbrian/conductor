<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use LogicException;

/**
 * Thrown when attempting scheduled dispatch on operations without Scheduled interface.
 *
 * This exception occurs when trying to execute an operation via the scheduled orchestrator
 * or ProcessScheduledCommand, but the operation class does not implement the Scheduled
 * contract. Operations must implement Scheduled to define their execution time via the
 * executeAt() method, which the orchestrator uses to determine when to execute the operation.
 *
 * This is a development-time error indicating missing interface implementation rather
 * than a runtime condition. Operations intended for scheduled execution must explicitly
 * declare they implement Scheduled and provide an execution time.
 *
 * ```php
 * // Incorrect - missing Scheduled interface:
 * final class MaintenanceOperation implements Operation
 * {
 *     public function handle(): void
 *     {
 *         // Maintenance logic
 *     }
 * }
 *
 * // Attempting scheduled dispatch throws exception:
 * try {
 *     $orchestrator = new ScheduledOrchestrator();
 *     $orchestrator->execute(new MaintenanceOperation());
 * } catch (OperationMustImplementScheduledException $e) {
 *     // Exception: Operation must implement Scheduled interface
 * }
 *
 * // Correct - implements Scheduled:
 * final class MaintenanceOperation implements Operation, Scheduled
 * {
 *     public function executeAt(): DateTimeInterface
 *     {
 *         return now()->setTime(2, 0); // Execute at 2am
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Maintenance logic
 *     }
 * }
 * ```
 *
 * @see \Cline\Sequencer\Contracts\Scheduled
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationMustImplementScheduledException extends LogicException implements SequencerException
{
    /**
     * Create exception for operation missing Scheduled interface.
     *
     * Thrown when attempting to dispatch an operation via the scheduled orchestrator
     * but the operation does not implement the Scheduled contract. This indicates the
     * operation cannot provide an execution time and should not be processed by the
     * scheduled execution system.
     *
     * @return self Exception instance with interface requirement message
     */
    public static function forDispatch(): self
    {
        return new self('Operation must implement Scheduled interface');
    }
}
