<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Contract for operations with custom tags for monitoring and organization.
 *
 * Operations implementing this interface can provide tags that Sequencer and Laravel's
 * queue system use for categorization, filtering, and metrics collection. Tags enable
 * grouping related operations, tracking operation types, and filtering queue jobs in
 * monitoring dashboards like Laravel Horizon.
 *
 * Tags are stored with the operation's job record and appear in queue monitoring tools,
 * log aggregation systems, and metrics dashboards. This makes it easier to analyze
 * operation patterns, debug specific operation categories, and monitor deployment
 * phases across multiple operations.
 *
 * Common tagging strategies:
 * - Functional categories: 'data-migration', 'cache-warming', 'notifications'
 * - Environment indicators: 'production-only', 'staging-safe'
 * - Business domains: 'billing', 'analytics', 'user-management'
 * - Priority levels: 'critical', 'standard', 'low-priority'
 * - Feature flags: 'new-feature', 'experimental', 'legacy'
 *
 * ```php
 * final class MigrateUserPreferences implements Operation, HasTags
 * {
 *     public function tags(): array
 *     {
 *         return [
 *             'data-migration',
 *             'user-management',
 *             'production-safe',
 *         ];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Migration logic
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface HasTags
{
    /**
     * Get the tags that should be assigned to this operation.
     *
     * Returns an array of string tags used for categorization, filtering, and monitoring.
     * Tags appear in Laravel Horizon, queue monitoring tools, and can be used for metrics
     * aggregation and log filtering.
     *
     * Keep tags concise and consistent across operations. Use kebab-case for multi-word
     * tags to ensure compatibility with monitoring tools and log aggregation systems.
     *
     * @return array<string> Array of tag strings for categorization and filtering
     */
    public function tags(): array;
}
