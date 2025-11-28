<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Database\Concerns;

use Cline\Sequencer\Enums\PrimaryKeyType;
use Cline\Sequencer\Exceptions\InvalidPrimaryKeyValueException;
use Cline\Sequencer\Support\PrimaryKeyGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

use function in_array;
use function is_string;

/**
 * Configures model primary keys based on Sequencer package configuration.
 *
 * This trait enables automatic primary key type detection and generation based
 * on the `sequencer.primary_key_type` configuration. It supports standard auto-incrementing
 * IDs, UUIDs, and ULIDs, automatically generating values during model creation.
 *
 * The trait integrates with Laravel's HasUuids and HasUlids traits by implementing
 * the newUniqueId() and uniqueIds() methods, while also overriding Eloquent's
 * getIncrementing() and getKeyType() methods to ensure proper behavior for each
 * primary key type.
 *
 * Usage:
 * ```php
 * use HasSequencerPrimaryKey;
 *
 * // Configuration determines primary key type:
 * // 'id' => auto-incrementing integers
 * // 'uuid' => UUID strings
 * // 'ulid' => ULID strings
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
trait HasSequencerPrimaryKey
{
    /**
     * Determine if the model's primary key is auto-incrementing.
     *
     * Overrides the default incrementing behavior to return false when the primary
     * key uses UUID or ULID identifiers, ensuring proper Eloquent behavior.
     *
     * @return bool False for UUID/ULID keys, true for auto-increment keys
     */
    public function getIncrementing(): bool
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return false;
        }

        return $this->incrementing;
    }

    /**
     * Get the data type of the model's primary key.
     *
     * Overrides the default key type to return 'string' for UUID/ULID identifiers
     * and maintains the configured type for standard auto-incrementing keys.
     *
     * @return string The key type ('string' for UUID/ULID, 'int' for standard IDs)
     */
    public function getKeyType(): string
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return 'string';
        }

        return $this->keyType;
    }

    /**
     * Generate a new unique identifier for the model.
     *
     * Called by Laravel's HasUuids/HasUlids traits during model creation to generate
     * the appropriate identifier type based on configuration.
     *
     * @return null|string The generated UUID/ULID string, or null for auto-increment keys
     */
    public function newUniqueId(): ?string
    {
        return PrimaryKeyGenerator::generate()->value;
    }

    /**
     * Get the columns that should use unique identifiers.
     *
     * Reads the sequencer.primary_key_type configuration to determine if the primary
     * key should use UUID or ULID identifiers, returning an array with the key name
     * or an empty array for standard auto-incrementing keys.
     *
     * @return list<string> Array containing the primary key name for UUID/ULID, empty for auto-increment
     */
    public function uniqueIds(): array
    {
        /** @var int|string $configValue */
        $configValue = Config::get('sequencer.primary_key_type', 'id');
        $primaryKeyType = PrimaryKeyType::tryFrom($configValue) ?? PrimaryKeyType::ID;

        return match ($primaryKeyType) {
            PrimaryKeyType::ULID, PrimaryKeyType::UUID => [$this->getKeyName()],
            PrimaryKeyType::ID => [],
        };
    }

    /**
     * Boot the trait and register model event listeners.
     *
     * Registers a 'creating' event listener that automatically generates primary key
     * values for UUID/ULID configurations when no value is present. When a value is
     * manually set, validates that it matches the expected type (string for UUID/ULID).
     * This prevents type mismatches that could cause database errors or unexpected behavior.
     *
     * The listener executes during model creation before the INSERT query, ensuring
     * the primary key is set correctly for new records while allowing manual override
     * when needed (e.g., for data migrations or seeding).
     *
     * @throws InvalidPrimaryKeyValueException When a manually-set UUID/ULID value is not a string
     */
    protected static function bootHasSequencerPrimaryKey(): void
    {
        static::creating(function (Model $model): void {
            $primaryKey = PrimaryKeyGenerator::generate();

            if ($primaryKey->isAutoIncrementing()) {
                return;
            }

            $keyName = $model->getKeyName();
            $existingValue = $model->getAttribute($keyName);

            if (!$existingValue) {
                $model->setAttribute($keyName, $primaryKey->value);

                return;
            }

            if ($primaryKey->type === PrimaryKeyType::UUID && !is_string($existingValue)) {
                throw InvalidPrimaryKeyValueException::nonStringUuid($existingValue);
            }

            if ($primaryKey->type === PrimaryKeyType::ULID && !is_string($existingValue)) {
                throw InvalidPrimaryKeyValueException::nonStringUlid($existingValue);
            }
        });
    }
}
