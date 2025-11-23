<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Events;

/**
 * Event dispatched after an individual operation completes execution.
 *
 * Mirrors Laravel's MigrationEnded event pattern. Fired immediately after an
 * operation's handle() method finishes, regardless of success or failure. The
 * operation's final state (completed, failed, skipped) has been persisted to
 * the database at dispatch time.
 *
 * Dispatched after operation execution completes and state is persisted. Pairs
 * with OperationStarted to bracket individual operation execution for timing
 * measurements and completion tracking.
 *
 * ```php
 * Event::listen(OperationEnded::class, function ($event) {
 *     $duration = $event->operation->executed_at->diffInMilliseconds(now());
 *     Metrics::timing("operation.{$event->operation->name}.duration", $duration);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationEnded extends OperationEvent {}
