<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Cline\Sequencer\Enums\PrimaryKeyType;

/**
 * Value object representing a primary key type and its generated value.
 *
 * Encapsulates primary key configuration (ID, UUID, ULID) with the generated value.
 * Provides query methods to determine handling strategy: auto-incrementing keys
 * return null and are database-generated, while UUIDs/ULIDs return pre-generated
 * string values.
 *
 * ```php
 * $key = new PrimaryKeyValue(PrimaryKeyType::UUID, Str::uuid());
 *
 * if ($key->requiresValue()) {
 *     $model->id = $key->value;
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class PrimaryKeyValue
{
    /**
     * Create a new primary key value object.
     *
     * @param PrimaryKeyType $type  The primary key type (ID, UUID, or ULID) that determines
     *                              whether the value should be pre-generated or database-assigned
     * @param null|string    $value The generated primary key value for UUID/ULID types, or null
     *                              for auto-incrementing IDs that will be assigned by the database
     */
    public function __construct(
        public PrimaryKeyType $type,
        public ?string $value,
    ) {}

    /**
     * Determine if the primary key type is auto-incrementing.
     *
     * Auto-incrementing keys are database-generated on insert and don't require
     * application-generated values.
     *
     * @return bool True if the primary key is auto-incrementing (ID type), false for UUID/ULID
     */
    public function isAutoIncrementing(): bool
    {
        return $this->type === PrimaryKeyType::ID;
    }

    /**
     * Determine if the primary key requires a pre-generated value.
     *
     * UUID and ULID keys must be application-generated before database insertion,
     * while auto-incrementing IDs are handled by the database.
     *
     * @return bool True if a value must be provided before insert (UUID/ULID), false for auto-increment
     */
    public function requiresValue(): bool
    {
        return $this->type !== PrimaryKeyType::ID;
    }
}
