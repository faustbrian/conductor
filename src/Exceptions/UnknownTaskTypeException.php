<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use LogicException;

use function sprintf;

/**
 * Thrown when attempting to process an unrecognized task type.
 *
 * This exception occurs when the system encounters a task type identifier that
 * does not match any registered task handlers or known task types. This typically
 * indicates a configuration error, corrupted data, or an attempt to process tasks
 * from a different system version that introduced new task types.
 *
 * Task types must be registered with the task processor before they can be executed.
 * If you're seeing this exception, verify that:
 * 1. The task type is properly registered in your configuration
 * 2. There are no typos in the task type identifier
 * 3. You're not loading tasks from an incompatible data source
 *
 * ```php
 * // Example scenario that would trigger this exception:
 * $taskData = [
 *     'type' => 'sync_users',  // Typo - should be 'sync-users'
 *     'payload' => [...],
 * ];
 *
 * try {
 *     $processor->processTask($taskData);
 * } catch (UnknownTaskTypeException $e) {
 *     // Exception: Unknown task type: sync_users
 *     Log::error('Cannot process task with unknown type', [
 *         'exception' => $e,
 *         'task' => $taskData,
 *         'registered_types' => $processor->getRegisteredTypes(),
 *     ]);
 * }
 *
 * // Proper task type registration:
 * $processor->registerTaskType('sync-users', SyncUsersHandler::class);
 * $processor->registerTaskType('send-email', SendEmailHandler::class);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class UnknownTaskTypeException extends LogicException
{
    /**
     * Create exception for unrecognized task type.
     *
     * Thrown when attempting to process a task whose type identifier does not match
     * any registered task handler. The unrecognized type is included in the error
     * message to assist debugging and configuration troubleshooting.
     *
     * @param  string $type The unrecognized task type identifier that was encountered
     * @return self   Exception instance with unknown task type error message
     */
    public static function forType(string $type): self
    {
        return new self(
            sprintf('Unknown task type: %s', $type),
        );
    }
}
