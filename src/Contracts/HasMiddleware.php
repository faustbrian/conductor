<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations that execute through middleware pipeline.
 *
 * Operations implementing this interface can define middleware layers that wrap the
 * operation execution, providing cross-cutting concerns like rate limiting, overlap
 * prevention, exception throttling, and conditional skipping without cluttering the
 * main handle() method.
 *
 * Middleware executes in the order specified by the middleware() method, forming a
 * pipeline where each middleware can decide to pass the operation to the next layer,
 * skip execution entirely, or modify execution behavior. This follows Laravel's
 * standard middleware pattern used in HTTP requests and queued jobs.
 *
 * Sequencer provides built-in middleware for common operation concerns:
 * - RateLimited: Enforce rate limits using Laravel's rate limiter
 * - WithoutOverlapping: Prevent concurrent execution via locks
 * - ThrottlesExceptions: Back off after repeated exceptions
 * - SkipIfBatchCancelled: Skip when parent batch is cancelled
 *
 * Custom middleware can be created by implementing the appropriate middleware interface
 * and following Laravel's middleware conventions.
 *
 * Common use cases:
 * - Rate limiting API calls to external services
 * - Preventing duplicate executions during retries
 * - Backing off operations that repeatedly fail
 * - Skipping operations when batch context changes
 * - Adding custom authorization or validation logic
 * - Implementing circuit breakers for external dependencies
 *
 * ```php
 * use Illuminate\Queue\Middleware\RateLimited;
 * use Illuminate\Queue\Middleware\WithoutOverlapping;
 *
 * final class SyncProductsFromAPI implements Operation, HasMiddleware
 * {
 *     public function middleware(): array
 *     {
 *         return [
 *             new RateLimited('external-api'),
 *             new WithoutOverlapping('sync-products'),
 *         ];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Execution is rate limited and prevents overlapping runs
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface HasMiddleware
{
    /**
     * Get the middleware pipeline the operation should execute through.
     *
     * Returns an array of middleware instances that wrap the operation execution.
     * Middleware executes in the order specified, with each middleware receiving
     * the next middleware in the chain. The final middleware executes handle().
     *
     * Middleware should follow Laravel's middleware conventions, accepting the
     * operation and next callable as parameters. Common middleware classes are
     * available in the Illuminate\Queue\Middleware namespace.
     *
     * @return array<object> Array of middleware instances to apply in order
     */
    public function middleware(): array;
}
