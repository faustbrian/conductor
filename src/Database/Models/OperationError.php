<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Database\Models;

use Cline\Sequencer\Database\Concerns\HasSequencerPrimaryKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * Eloquent model representing operation execution errors.
 *
 * Stores detailed error information when operations fail, including exception
 * messages, stack traces, and contextual data for debugging and recovery.
 * Provides a complete audit trail of all operation failures.
 *
 * Each error record captures the full exception details at the moment of failure,
 * preserving critical debugging information even if the operation is retried or
 * rolled back. The context field stores additional data like request parameters,
 * user state, or environment variables that may be relevant for troubleshooting.
 *
 * @property null|array<string, mixed> $context      Additional context data (request params, state, etc.)
 * @property Carbon                    $created_at   When error was recorded
 * @property string                    $exception    Exception class name
 * @property mixed                     $id           Primary key (auto-increment, UUID, or ULID)
 * @property string                    $message      Exception message
 * @property Operation                 $operation    The operation that failed
 * @property mixed                     $operation_id Foreign key to operations table
 * @property string                    $trace        Stack trace
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationError extends Model
{
    /** @use HasFactory<Factory<OperationError>> */
    use HasFactory;
    use HasSequencerPrimaryKey;

    /**
     * Indicates if the model should be timestamped.
     *
     * Disabled because the model uses only created_at without updated_at,
     * since error records are immutable after creation. Errors capture a
     * point-in-time snapshot of failure state and should never be modified.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * Allows bulk assignment of all error fields during creation. All fields are
     * fillable since error records are created programmatically from caught exceptions
     * rather than user input, eliminating mass assignment vulnerability concerns.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'operation_id',
        'exception',
        'message',
        'trace',
        'context',
        'created_at',
    ];

    /**
     * Get the table name from configuration.
     *
     * Retrieves the configured table name from sequencer.table_names.operation_errors,
     * defaulting to 'operation_errors' if not configured. Allows customization of the
     * table name without modifying the model.
     *
     * @return string The configured table name for operation error storage
     */
    #[Override()]
    public function getTable(): string
    {
        /** @var string */
        return Config::get('sequencer.table_names.operation_errors', 'operation_errors');
    }

    /**
     * Get the operation this error belongs to.
     *
     * Defines a many-to-one relationship back to the parent Operation model that
     * experienced this error. Enables querying all errors for a specific operation
     * or navigating from error to operation for context.
     *
     * @return BelongsTo<Operation, $this> Parent operation relationship
     */
    public function operation(): BelongsTo
    {
        /** @var class-string<Operation> $operationModel */
        $operationModel = Config::get('sequencer.models.operation', Operation::class);

        return $this->belongsTo($operationModel, 'operation_id');
    }

    /**
     * Get the attribute casting configuration.
     *
     * Configures automatic casting for the context array and created_at timestamp,
     * enabling convenient access to structured error context and datetime manipulation.
     * The context field is automatically serialized/unserialized as JSON when stored
     * in the database.
     *
     * @return array<string, string> Attribute casting map
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
