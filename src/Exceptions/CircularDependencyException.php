<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

/**
 * Thrown when circular dependencies are detected between operations.
 *
 * This exception occurs when operation dependencies form a cycle, making it impossible
 * to build valid execution waves. For example: Operation A depends on B, B depends on C,
 * and C depends on A. Circular dependencies prevent proper wave construction and must
 * be resolved by removing or reordering dependencies.
 *
 * ```php
 * // Example scenario that would trigger this exception:
 * $operationA->dependsOn([$operationB]);
 * $operationB->dependsOn([$operationC]);
 * $operationC->dependsOn([$operationA]); // Creates circular dependency
 *
 * try {
 *     $sequencer->execute(); // Attempts to build waves
 * } catch (CircularDependencyException $e) {
 *     // Circular dependency detected - fix operation dependencies
 *     Log::error('Circular dependency in operations', ['exception' => $e]);
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class CircularDependencyException extends RuntimeException implements SequencerException
{
    /**
     * Create exception for detected circular dependency.
     *
     * Thrown when the wave builder algorithm detects a cycle in the operation dependency
     * graph. This indicates that operations cannot be ordered into sequential waves because
     * there is no valid topological ordering.
     *
     * @return self Exception instance with circular dependency error message
     */
    public static function detected(): self
    {
        return new self('Circular dependency detected - cannot build execution waves');
    }
}
