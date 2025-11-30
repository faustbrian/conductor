<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Marker interface for operations that execute asynchronously via queue workers.
 *
 * Operations implementing this interface are dispatched to the configured queue system
 * instead of executing synchronously during orchestration. While the operation maintains
 * its chronological position in the execution sequence, it runs in the background without
 * blocking the deployment process, allowing subsequent operations to begin immediately.
 *
 * Sequencer tracks asynchronous operations in the database and monitors their completion
 * status. The deployment proceeds without waiting for async operations to finish, making
 * this pattern ideal for long-running tasks where immediate completion is not required
 * for deployment success.
 *
 * Queue configuration, connection settings, retry logic, and timeout behavior follow
 * Laravel's standard queue configuration. Operations can implement additional interfaces
 * like Retryable or HasMaxExceptions to control failure handling behavior.
 *
 * Common use cases:
 * - Bulk data processing that would timeout in synchronous execution
 * - External API synchronization where latency is unpredictable
 * - Cache warming across distributed systems
 * - Notification distribution to large user segments
 * - Data exports or report generation
 *
 * ```php
 * final class SyncProductCatalogWithExternalAPI implements Operation, Asynchronous
 * {
 *     public function handle(): void
 *     {
 *         // Dispatched to queue, deployment continues without waiting
 *         $products = Http::get('https://api.example.com/products')->json();
 *         Product::upsert($products, ['external_id'], ['name', 'price']);
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface Asynchronous extends Operation
{
    // Marker interface - no additional methods required
}
