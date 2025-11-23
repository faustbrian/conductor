<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Sequencer\Enums\MorphType;
use Cline\Sequencer\Enums\PrimaryKeyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for creating Sequencer operation tracking tables.
 *
 * This migration creates two tables for the Sequencer operation orchestration system:
 * - operations: stores execution records for all processed operations
 * - operation_errors: stores detailed error information when operations fail
 *
 * The primary key type (ID, ULID, UUID) is configured via the sequencer.primary_key_type
 * configuration option to support different application requirements.
 *
 * @see config/sequencer.php
 */
return new class() extends Migration
{
    /**
     * Run the migrations to create operation tracking tables.
     */
    public function up(): void
    {
        $primaryKeyType = PrimaryKeyType::tryFrom(config('sequencer.primary_key_type', 'id')) ?? PrimaryKeyType::ID;
        $morphType = MorphType::tryFrom(config('sequencer.morph_type', 'morph')) ?? MorphType::Morph;

        $connection = config('database.default');
        $useJsonb = DB::connection($connection)->getDriverName() === 'pgsql';

        // Create operations table
        Schema::create(config('sequencer.table_names.operations', 'operations'), function (Blueprint $table) use ($primaryKeyType, $morphType): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            $table->string('name')->comment('Operation class name');
            $table->enum('type', [
                'sync',
                'async',
                'batch',
                'chain',
                'dependency_graph',
                'scheduled',
                'allowed_to_fail_batch',
                'transactional_batch',
            ])->comment('Execution type');

            // Who executed this operation
            match ($morphType) {
                MorphType::ULID => $table->nullableUlidMorphs('executed_by'),
                MorphType::UUID => $table->nullableUuidMorphs('executed_by'),
                MorphType::Numeric => $table->nullableNumericMorphs('executed_by'),
                MorphType::Morph => $table->nullableMorphs('executed_by'),
            };

            $table->timestamp('executed_at')->comment('When operation started');
            $table->timestamp('completed_at')->nullable()->comment('When operation completed successfully');
            $table->timestamp('failed_at')->nullable()->comment('When operation failed');
            $table->timestamp('skipped_at')->nullable()->comment('When operation was skipped');
            $table->text('skip_reason')->nullable()->comment('Reason operation was skipped');
            $table->timestamp('rolled_back_at')->nullable()->comment('When operation was rolled back');
            $table->string('state')->default('pending')->comment('Current execution state');

            // Indexes for common query patterns
            $table->index('name', 'operations_name_idx');
            $table->index('type', 'operations_type_idx');
            $table->index('executed_at', 'operations_executed_idx');
            $table->index(['executed_by_type', 'executed_by_id'], 'operations_executor_idx');
            $table->unique(['name', 'executed_at'], 'operations_name_executed_unique');
        });

        // Create operation_errors table
        Schema::create(config('sequencer.table_names.operation_errors', 'operation_errors'), function (Blueprint $table) use ($primaryKeyType, $useJsonb): void {
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->ulid('id')->primary(),
                PrimaryKeyType::UUID => $table->uuid('id')->primary(),
                PrimaryKeyType::ID => $table->id(),
            };

            // Foreign key to operation
            $operationsTable = config('sequencer.table_names.operations', 'operations');
            match ($primaryKeyType) {
                PrimaryKeyType::ULID => $table->foreignUlid('operation_id')->constrained($operationsTable)->cascadeOnDelete(),
                PrimaryKeyType::UUID => $table->foreignUuid('operation_id')->constrained($operationsTable)->cascadeOnDelete(),
                PrimaryKeyType::ID => $table->foreignId('operation_id')->constrained($operationsTable)->cascadeOnDelete(),
            };

            $table->string('exception')->comment('Exception class name');
            $table->text('message')->comment('Exception message');
            $table->longText('trace')->comment('Stack trace');
            $useJsonb
                ? $table->jsonb('context')->nullable()->comment('Additional context data')
                : $table->json('context')->nullable()->comment('Additional context data');
            $table->timestamp('created_at')->comment('When error was recorded');

            $table->index('operation_id', 'operation_errors_operation_idx');
            $table->index('exception', 'operation_errors_exception_idx');
            $table->index('created_at', 'operation_errors_created_idx');
        });
    }

    /**
     * Reverse the migrations by dropping all operation tracking tables.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('sequencer.table_names.operation_errors', 'operation_errors'));
        Schema::dropIfExists(config('sequencer.table_names.operations', 'operations'));
    }
};
