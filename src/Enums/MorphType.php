<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Enums;

/**
 * Defines polymorphic relationship types for operation executor tracking.
 *
 * Specifies the identifier format used in polymorphic relationships that track
 * which model/user executed an operation. The chosen type must match the primary
 * key type of the models being referenced (typically User or other executor models).
 * Maps to Laravel's Blueprint morph method variants.
 *
 * ```php
 * // Configuration example
 * 'morph_type' => MorphType::UUID,  // For User model with UUID primary keys
 *
 * // Migration usage (handled automatically)
 * $table->uuidMorphs('executor');  // Creates executor_type and executor_id columns
 * ```
 *
 * @see https://laravel.com/docs/migrations#column-method-morphs
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum MorphType: string
{
    /**
     * Auto-detecting polymorphic relationship.
     *
     * Uses Laravel's morphs() method which automatically infers the appropriate
     * column type based on the referenced model's primary key. Creates BIGINT
     * UNSIGNED columns by default. Provides flexibility at the cost of explicit
     * type declaration.
     */
    case Morph = 'morph';

    /**
     * Integer-based polymorphic relationship.
     *
     * Explicitly creates BIGINT UNSIGNED foreign key columns for the morph
     * relationship via numericMorphs(). Use when executor models (e.g., User)
     * have standard auto-incrementing integer primary keys. Most common choice
     * for traditional Laravel applications.
     */
    case Numeric = 'numericMorph';

    /**
     * UUID-based polymorphic relationship.
     *
     * Creates CHAR(36) columns for storing UUID identifiers via uuidMorphs().
     * Use when executor models have UUID primary keys. Provides globally unique,
     * cryptographically random identifiers but sacrifices chronological sorting
     * and uses more storage than ULIDs or integers.
     */
    case UUID = 'uuidMorph';

    /**
     * ULID-based polymorphic relationship.
     *
     * Creates CHAR(26) columns for storing ULID identifiers via ulidMorphs().
     * Use when executor models have ULID primary keys. Combines UUID's global
     * uniqueness with chronological sortability and improved storage efficiency.
     * Ideal for distributed systems requiring time-ordered identifiers.
     */
    case ULID = 'ulidMorph';
}
