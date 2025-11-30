<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

use Illuminate\Contracts\Cache\Repository;

/**
 * Contract for operations that enforce single-instance execution via distributed locks.
 *
 * Operations implementing this interface acquire an exclusive lock before execution begins
 * and release it upon completion, preventing concurrent execution of operations with the
 * same unique identifier. This ensures critical operations cannot run simultaneously across
 * multiple queue workers, servers, or deployment processes.
 *
 * Sequencer checks for lock availability before executing the operation. If an identical
 * operation (same unique ID) is already running, the new instance is silently dropped from
 * the queue rather than waiting or retrying. This prevents queue buildup and resource waste
 * for operations where concurrent execution provides no value.
 *
 * Locks are stored in Laravel's cache system, leveraging atomic cache operations to prevent
 * race conditions during lock acquisition. The lock persists for the duration specified by
 * uniqueFor(), providing automatic cleanup even if the operation crashes or times out
 * without releasing the lock explicitly.
 *
 * The unique ID should incorporate all factors that determine operation identity. For simple
 * uniqueness, use the operation class name. For parameter-specific uniqueness, include
 * relevant parameters in the ID to allow different instances to run concurrently while
 * preventing duplicate instances with identical parameters.
 *
 * Common use cases:
 * - External API synchronization preventing duplicate requests
 * - File processing where concurrent access causes corruption
 * - Database migrations requiring exclusive table access
 * - Cache rebuilding operations that invalidate during execution
 * - Report generation preventing resource-intensive duplicates
 * - Webhook processing enforcing idempotency
 *
 * ```php
 * final class SyncUserDataWithCRM implements Operation, Asynchronous, ShouldBeUnique
 * {
 *     public function __construct(
 *         private readonly int $userId,
 *     ) {}
 *
 *     public function uniqueId(): string
 *     {
 *         // Prevent concurrent syncs for the same user, allow different users
 *         return 'sync-user-' . $this->userId;
 *     }
 *
 *     public function uniqueFor(): int
 *     {
 *         return 300; // Lock expires after 5 minutes
 *     }
 *
 *     public function uniqueVia(): ?Repository
 *     {
 *         return Cache::store('redis'); // Use Redis for distributed locks
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Guaranteed only one sync per user at a time
 *         $user = User::find($this->userId);
 *         CRM::syncUser($user);
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface ShouldBeUnique
{
    /**
     * Get the unique identifier that enforces single-instance execution.
     *
     * Returns a string uniquely identifying this operation instance for lock acquisition.
     * Operations with identical unique IDs cannot execute concurrently - attempting to
     * execute a second instance while the first holds the lock results in the second
     * instance being dropped from the queue.
     *
     * The unique ID should incorporate all factors determining operation identity. Use
     * the class name for class-level uniqueness, or combine class name with parameters
     * for parameter-specific uniqueness. This allows fine-grained control over which
     * operations can run concurrently.
     *
     * @return string Unique identifier for lock acquisition and concurrency control
     */
    public function uniqueId(): string;

    /**
     * Get the lock duration in seconds before automatic release.
     *
     * Determines how long the exclusive lock persists in the cache system. The lock
     * is released when execution completes successfully, but this duration provides
     * automatic cleanup if the operation crashes, times out, or the worker process
     * terminates unexpectedly.
     *
     * Set this value longer than the expected execution time to prevent lock expiration
     * during normal operation, but short enough to allow recovery from crashed workers.
     * A common approach is 2-3x the expected execution time.
     *
     * @return int Lock duration in seconds before automatic release
     */
    public function uniqueFor(): int;

    /**
     * Get the cache repository for distributed lock storage.
     *
     * Specifies which cache driver stores the unique lock, enabling distributed lock
     * coordination across multiple servers and queue workers. Return a specific cache
     * repository instance to use dedicated lock storage, or null to use the default
     * cache driver configured in the application.
     *
     * For multi-server deployments, use a shared cache driver like Redis or Memcached
     * to ensure locks coordinate across all servers. Local cache drivers like file or
     * array only provide single-server uniqueness.
     *
     * @return null|Repository Cache repository for lock storage, or null for default driver
     */
    public function uniqueVia(): ?Repository;
}
