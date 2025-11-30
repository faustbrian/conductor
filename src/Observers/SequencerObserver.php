<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Observers;

use Cline\Sequencer\Database\Models\Operation;
use Illuminate\Support\Facades\Config;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

use function class_exists;

/**
 * Observer for broadcasting Sequencer operation events to monitoring tools.
 *
 * Integrates with Laravel Pulse and Telescope to provide real-time monitoring
 * and historical tracking of operation lifecycle events. Automatically records
 * operation creation, completion, failure, and rollback events when these
 * monitoring tools are enabled in the application configuration.
 *
 * The observer uses Laravel's model event system to intercept database changes
 * and broadcast them to configured monitoring tools. Events are only recorded
 * if the respective tools are installed and enabled in sequencer.reporting config.
 *
 * Configuration options:
 * - sequencer.reporting.pulse: Enable Pulse integration for real-time metrics
 * - sequencer.reporting.telescope: Enable Telescope integration for debugging
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class SequencerObserver
{
    /**
     * Handle the operation created event.
     *
     * Records the operation start event to Pulse and Telescope when the
     * operation record is first created in the database. This occurs when
     * an operation begins execution, providing visibility into active operations.
     *
     * @param Operation $operation The operation model instance that was created in the
     *                             database. Contains operation name, type, timestamps,
     *                             and metadata for monitoring and audit trail purposes.
     */
    public function created(Operation $operation): void
    {
        $this->recordPulse('operation.started', $operation);
        $this->recordTelescope('OperationStarted', $operation);
    }

    /**
     * Handle the operation updated event.
     *
     * Monitors state changes on the operation model and records appropriate
     * events when completion, failure, or rollback timestamps are set for
     * the first time. Only fires when the specific timestamp fields change
     * to prevent duplicate event recording on subsequent updates. Enables
     * tracking of operation lifecycle transitions (started → completed/failed/rolled_back).
     *
     * @param Operation $operation The operation model instance that was updated in the
     *                             database. Checked for changes in completed_at, failed_at,
     *                             and rolled_back_at timestamps to determine which lifecycle
     *                             event occurred and should be recorded to monitoring tools.
     */
    public function updated(Operation $operation): void
    {
        if ($operation->completed_at && $operation->wasChanged('completed_at')) {
            $this->recordPulse('operation.completed', $operation);
            $this->recordTelescope('OperationCompleted', $operation);
        }

        if ($operation->failed_at && $operation->wasChanged('failed_at')) {
            $this->recordPulse('operation.failed', $operation);
            $this->recordTelescope('OperationFailed', $operation);
        }

        if ($operation->rolled_back_at && $operation->wasChanged('rolled_back_at')) {
            $this->recordPulse('operation.rolled_back', $operation);
            $this->recordTelescope('OperationRolledBack', $operation);
        }
    }

    /**
     * Record an operation event to Laravel Pulse for real-time monitoring.
     *
     * Only records the event if Pulse reporting is enabled in configuration
     * (sequencer.reporting.pulse) and the Laravel Pulse package is installed.
     * Events are recorded as counter metrics with the operation name as the key
     * for aggregation and trend analysis. The counter increments by 1 for each
     * event, enabling dashboard visualization of operation execution patterns.
     *
     * @param string    $type      The event type suffix appended to 'sequencer.' prefix.
     *                             Common values: 'operation.started', 'operation.completed',
     *                             'operation.failed', 'operation.rolled_back'. Used for
     *                             categorizing and filtering events in Pulse dashboard.
     * @param Operation $operation The operation model instance to record. The operation name
     *                             is used as the metric key for grouping and aggregating
     *                             related events in time-series visualizations.
     */
    private function recordPulse(string $type, Operation $operation): void
    {
        if (!Config::get('sequencer.reporting.pulse', false)) {
            return;
        }

        if (!class_exists(Pulse::class)) {
            return;
        }

        Pulse::record(
            type: 'sequencer.'.$type,
            key: $operation->name,
            value: 1,
        )->count();
    }

    /**
     * Record an operation event to Laravel Telescope for debugging and monitoring.
     *
     * Only records the event if Telescope reporting is enabled in configuration
     * (sequencer.reporting.telescope) and the Laravel Telescope package is installed.
     * Creates a custom event entry with the operation's lifecycle timestamps for
     * historical analysis, debugging, and troubleshooting operation execution issues.
     *
     * @param string    $type      The event type identifier used for categorizing events
     *                             in Telescope. Common values: 'OperationStarted',
     *                             'OperationCompleted', 'OperationFailed', 'OperationRolledBack'.
     *                             Used for filtering and searching events in Telescope UI.
     * @param Operation $operation The operation model instance to record. All lifecycle
     *                             timestamps (executed_at, completed_at, failed_at, rolled_back_at)
     *                             are included in the event entry for complete operation state
     *                             visibility and timeline analysis in Telescope dashboard.
     */
    private function recordTelescope(string $type, Operation $operation): void
    {
        if (!Config::get('sequencer.reporting.telescope', false)) {
            return;
        }

        if (!class_exists(Telescope::class)) {
            return;
        }

        Telescope::recordEvent(
            IncomingEntry::make([
                'type' => $type,
                'operation' => $operation->name,
                'executed_at' => $operation->executed_at,
                'completed_at' => $operation->completed_at,
                'failed_at' => $operation->failed_at,
                'rolled_back_at' => $operation->rolled_back_at,
            ]),
        );
    }
}
