<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Testing;

use Cline\Sequencer\Contracts\Operation;
use Closure;
use PHPUnit\Framework\Assert as PHPUnit;

use function count;
use function sprintf;

/**
 * Test double for operation execution tracking.
 *
 * Provides test fake capabilities to intercept and track operation executions
 * without actually running them. Enables isolated testing of operation dispatch
 * logic with PHPUnit-style assertions for verification.
 *
 * ```php
 * // Setup fake
 * OperationFake::setup();
 *
 * // Execute code that dispatches operations
 * $service->performAction();
 *
 * // Assert operations were dispatched
 * OperationFake::assertDispatched(CreateUserOperation::class);
 * OperationFake::assertNotDispatched(DeleteUserOperation::class);
 * OperationFake::assertDispatchedTimes(SendEmailOperation::class, 2);
 *
 * // Assert with callback to inspect operation state
 * OperationFake::assertDispatched(
 *     CreateUserOperation::class,
 *     fn ($op) => $op->userId === 123
 * );
 *
 * // Teardown
 * OperationFake::tearDown();
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationFake
{
    /**
     * Tracked operation executions during fake mode.
     *
     * @var array<int, array{class: string, operation: Operation}>
     */
    private static array $executed = [];

    /**
     * Whether fake mode is currently enabled.
     */
    private static bool $faking = false;

    /**
     * Enable operation faking mode.
     *
     * Activates fake mode and clears any previously tracked executions. After calling
     * this, operations will be recorded but not executed.
     */
    public static function setup(): void
    {
        self::$faking = true;
        self::$executed = [];
    }

    /**
     * Disable operation faking and clear tracked operations.
     *
     * Deactivates fake mode and resets the execution tracking array. Typically called
     * in test teardown to restore normal operation behavior.
     */
    public static function tearDown(): void
    {
        self::$faking = false;
        self::$executed = [];
    }

    /**
     * Check if faking is currently enabled.
     *
     * @return bool True if fake mode is active, false otherwise
     */
    public static function isFaking(): bool
    {
        return self::$faking;
    }

    /**
     * Record an operation execution during fake mode.
     *
     * Stores the operation class name and instance for later assertions. Only records
     * when fake mode is active; otherwise does nothing.
     *
     * @param string    $class     The fully-qualified operation class name
     * @param Operation $operation The operation instance being executed
     */
    public static function record(string $class, Operation $operation): void
    {
        if (!self::$faking) {
            return;
        }

        self::$executed[] = [
            'class' => $class,
            'operation' => $operation,
        ];
    }

    /**
     * Get all executed operations.
     *
     * Returns the complete list of tracked operation executions for custom assertions
     * or inspection.
     *
     * @return array<int, array{class: string, operation: Operation}>
     *                                                                Array of executed operations with class name and operation instance
     */
    public static function executed(): array
    {
        return self::$executed;
    }

    /**
     * Assert that an operation was dispatched at least once.
     *
     * Verifies the operation class was executed. Optional callback allows inspection
     * of the operation instance to validate specific state or properties.
     *
     * @param string       $class    The fully-qualified operation class name to verify
     * @param null|Closure $callback Optional callback receiving operation instance, should
     *                               return true if operation matches expected state
     */
    public static function assertDispatched(string $class, ?Closure $callback = null): void
    {
        $count = self::countDispatched($class, $callback);

        PHPUnit::assertGreaterThan(
            0,
            $count,
            sprintf('The expected [%s] operation was not dispatched.', $class),
        );
    }

    /**
     * Assert that an operation was not dispatched.
     *
     * Verifies the operation class was never executed, or if callback provided,
     * that no executions matched the callback criteria.
     *
     * @param string       $class    The fully-qualified operation class name to verify
     * @param null|Closure $callback Optional callback receiving operation instance, should
     *                               return true to indicate a matching execution
     */
    public static function assertNotDispatched(string $class, ?Closure $callback = null): void
    {
        $count = self::countDispatched($class, $callback);

        PHPUnit::assertSame(
            0,
            $count,
            sprintf('The unexpected [%s] operation was dispatched %d time(s).', $class, $count),
        );
    }

    /**
     * Assert that an operation was dispatched an exact number of times.
     *
     * Verifies the operation class was executed exactly the specified number of times.
     * Useful for testing retry logic, batch operations, or ensuring operations aren't
     * executed more times than expected.
     *
     * @param string $class The fully-qualified operation class name to verify
     * @param int    $times Expected number of times the operation should have been dispatched
     */
    public static function assertDispatchedTimes(string $class, int $times): void
    {
        $count = self::countDispatched($class);

        PHPUnit::assertSame(
            $times,
            $count,
            sprintf('The expected [%s] operation was dispatched %d times instead of %d times.', $class, $count, $times),
        );
    }

    /**
     * Assert that no operations were dispatched.
     *
     * Verifies the execution tracking is empty, useful for testing code paths
     * that should not trigger any operations.
     */
    public static function assertNothingDispatched(): void
    {
        $count = count(self::$executed);

        PHPUnit::assertSame(
            0,
            $count,
            sprintf('Expected no operations to be dispatched, but %d operation(s) were dispatched.', $count),
        );
    }

    /**
     * Count how many times an operation was dispatched.
     *
     * Iterates through tracked executions and counts matches based on class name
     * and optional callback criteria.
     *
     * @param  string       $class    The fully-qualified operation class name to count
     * @param  null|Closure $callback Optional callback to filter executions, receives operation
     *                                instance and should return true for matching executions
     * @return int          Number of times the operation was dispatched matching the criteria
     */
    private static function countDispatched(string $class, ?Closure $callback = null): int
    {
        $count = 0;

        foreach (self::$executed as $execution) {
            if ($execution['class'] !== $class) {
                continue;
            }

            if ($callback instanceof Closure && !$callback($execution['operation'])) {
                continue;
            }

            ++$count;
        }

        return $count;
    }
}
