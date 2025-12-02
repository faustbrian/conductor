<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Cline\Sequencer\Enums\PrimaryKeyType;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Generates primary key values for operations based on application configuration.
 *
 * Supports multiple primary key strategies: auto-incrementing IDs, UUIDs, and ULIDs.
 * The type is determined by the sequencer.primary_key_type config value, allowing
 * consistent key generation across all operation records.
 *
 * ```php
 * // Generate primary key based on config
 * $key = PrimaryKeyGenerator::generate();
 *
 * if ($key->requiresValue()) {
 *     // UUID/ULID - use generated value
 *     $operation->id = $key->value;
 * }
 * // Auto-incrementing - database handles it
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PrimaryKeyGenerator
{
    /**
     * Generate a new primary key value based on configuration.
     *
     * Returns a value object containing the configured primary key type and its
     * generated value. Auto-incrementing IDs return null (database-generated),
     * while UUIDs and ULIDs are pre-generated in lowercase format for consistency.
     *
     * @return PrimaryKeyValue Value object with key type and generated value (or null for auto-increment)
     */
    public static function generate(): PrimaryKeyValue
    {
        /** @var int|string $configValue */
        $configValue = Config::get('sequencer.primary_key_type', 'id');
        $primaryKeyType = PrimaryKeyType::tryFrom($configValue) ?? PrimaryKeyType::ID;

        $value = match ($primaryKeyType) {
            PrimaryKeyType::ULID => Str::lower((string) Str::ulid()),
            PrimaryKeyType::UUID => Str::lower((string) Str::uuid()),
            PrimaryKeyType::ID => null,
        };

        return new PrimaryKeyValue($primaryKeyType, $value);
    }
}
