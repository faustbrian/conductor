<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Events;

/**
 * Event dispatched before an individual operation begins execution.
 *
 * Mirrors Laravel's MigrationStarted event pattern. Fired immediately before
 * an operation's handle() method is called, after the operation has been loaded
 * and its dependencies resolved but before any operation logic executes.
 *
 * Dispatched before operation execution starts. Pairs with OperationEnded to
 * bracket individual operation execution for timing measurements, resource
 * allocation, and pre-execution validation or logging.
 *
 * ```php
 * Event::listen(OperationStarted::class, function ($event) {
 *     Log::info("Starting {$event->operation->name}", [
 *         'method' => $event->method->value,
 *         'payload_size' => strlen(json_encode($event->operation->payload)),
 *     ]);
 * });
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationStarted extends OperationEvent {}
