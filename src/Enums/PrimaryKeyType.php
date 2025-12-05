<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Enums;

/**
 * Defines primary key types for Sequencer operation tracking tables.
 *
 * Determines both the migration schema definition and ID generation strategy
 * for operation records. The choice affects database storage, bulk insert
 * performance, and distributed system compatibility. Maps to Laravel's Blueprint
 * primary key methods.
 *
 * ```php
 * // Configuration example
 * 'primary_key_type' => PrimaryKeyType::ULID,
 *
 * // Migration impact (handled automatically)
 * $table->ulid('id')->primary();  // Instead of $table->id()
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://laravel.com/docs/migrations#column-method-id
 */
enum PrimaryKeyType: string
{
    /**
     * Auto-incrementing BIGINT UNSIGNED primary key.
     *
     * Uses database-level auto-increment for ID generation via $table->id().
     * No application-level pre-generation required, simplifying bulk inserts
     * at the cost of requiring separate queries or database-specific RETURNING
     * clauses to retrieve generated IDs. Best for single-server deployments
     * with simple ID requirements.
     */
    case ID = 'id';

    /**
     * ULID (Universally Unique Lexicographically Sortable Identifier) primary key.
     *
     * Generates 26-character case-insensitive Base32-encoded identifiers via
     * $table->ulid('id')->primary(). Combines timestamp-based ordering with
     * random uniqueness, stored as CHAR(26). Pre-generated in application code
     * for bulk inserts, enabling efficient single-query batch insertion without
     * database roundtrips. Ideal for distributed systems requiring both sortability
     * and global uniqueness.
     */
    case ULID = 'ulid';

    /**
     * UUID (Universally Unique Identifier) primary key.
     *
     * Generates 36-character hyphenated hexadecimal identifiers (8-4-4-4-12 format)
     * via $table->uuid('id')->primary(). Provides cryptographically random global
     * uniqueness with no temporal ordering, stored as CHAR(36). Pre-generated in
     * application code for bulk inserts. Suitable when global uniqueness is required
     * but chronological sorting is not important or when maximum randomness is desired.
     */
    case UUID = 'uuid';
}
