<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Database\Models;

use Cline\Sequencer\Database\Builders\OperationBuilder;
use Cline\Sequencer\Database\Concerns\HasSequencerPrimaryKey;
use Cline\Sequencer\Enums\OperationState;
use Database\Factories\Cline\Sequencer\Database\Models\OperationFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing executed operations.
 *
 * Stores records of all operations that have been executed, including their
 * name, timestamp, execution status, and who performed them. Provides a
 * complete audit trail of all operations processed by Sequencer.
 *
 * Each operation record tracks its lifecycle through various timestamp fields
 * (executed_at, completed_at, failed_at, skipped_at, rolled_back_at), enabling
 * precise monitoring and debugging of operation execution. The polymorphic
 * executor relationship allows tracking which user, system, or entity triggered
 * each operation.
 *
 * @property null|Model                      $actor          Who executed this operation
 * @property null|string                     $actor_id       Polymorphic ID of actor
 * @property null|string                     $actor_type     Polymorphic type of actor
 * @property null|Carbon                     $completed_at   When operation completed successfully
 * @property Collection<int, OperationError> $errors         Collection of error records
 * @property Carbon                          $executed_at    When operation was executed
 * @property null|Carbon                     $failed_at      When operation failed
 * @property mixed                           $id             Primary key (auto-increment, UUID, or ULID)
 * @property string                          $name           Operation class name
 * @property null|Carbon                     $rolled_back_at When operation was rolled back
 * @property null|string                     $skip_reason    Reason operation was skipped
 * @property null|Carbon                     $skipped_at     When operation was skipped
 * @property OperationState                  $state          Current execution state (computed)
 * @property string                          $type           Operation type (sync|async)
 *
 * @author Brian Faust <brian@cline.sh>
 */
#[UseEloquentBuilder(OperationBuilder::class)]
final class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use HasFactory;
    use HasSequencerPrimaryKey;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of operation lifecycle fields during creation and updates.
     * All timestamp fields are fillable to support both automatic and manual operation
     * tracking scenarios, including data migrations and testing.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'type',
        'state',
        'actor_type',
        'actor_id',
        'executed_at',
        'completed_at',
        'failed_at',
        'skipped_at',
        'skip_reason',
        'rolled_back_at',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from sequencer.table_names.operations,
     * defaulting to 'operations' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for operation storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('sequencer.table_names.operations', 'operations');
    }

    /**
     * Get the polymorphic actor of this operation.
     *
     * Defines a polymorphic relationship to the entity that executed this operation,
     * which could be a User, System, or any other model. Enables tracking of who
     * or what triggered each operation for audit purposes.
     *
     * @return MorphTo<Model, $this> Polymorphic relation to the actor entity
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all errors for this operation.
     *
     * Defines a one-to-many relationship to error records captured during operation
     * execution. Each failed operation can have multiple error entries if it was
     * retried or if multiple exceptions occurred during processing.
     *
     * @return HasMany<OperationError, $this> Collection of error records
     */
    public function errors(): HasMany
    {
        /** @var class-string<OperationError> $model */
        $model = Config::get('sequencer.models.operation_error', OperationError::class);

        return $this->hasMany($model, 'operation_id');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Configures automatic casting of timestamp columns to Carbon instances for
     * convenient date/time manipulation and formatting throughout the application.
     * Also casts the state field to the OperationState enum for type-safe state
     * checking and transitions.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'skipped_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'state' => OperationState::class,
        ];
    }
}
