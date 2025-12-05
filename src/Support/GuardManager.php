<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Cline\Sequencer\Contracts\ExecutionGuard;
use Cline\Sequencer\Exceptions\ExecutionGuardException;
use InvalidArgumentException;

use function array_all;
use function class_exists;
use function config;
use function is_a;
use function sprintf;
use function throw_unless;

/**
 * Manages execution guards and evaluates them before operation processing.
 *
 * The GuardManager is responsible for:
 * - Loading guard configurations from config/sequencer.php
 * - Instantiating guard classes with their configuration
 * - Evaluating all guards and throwing if any blocks execution
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class GuardManager
{
    /**
     * Cached guard instances.
     *
     * @var null|array<ExecutionGuard>
     */
    private ?array $guards = null;

    /**
     * Check all configured guards and throw if any blocks execution.
     *
     * Iterates through all configured guards and calls shouldExecute() on each.
     * If any guard returns false, throws ExecutionGuardException with the
     * blocking guard's reason.
     *
     * @throws ExecutionGuardException When a guard blocks execution
     */
    public function check(): void
    {
        foreach ($this->getGuards() as $guard) {
            throw_unless($guard->shouldExecute(), ExecutionGuardException::class, $guard);
        }
    }

    /**
     * Check if execution is allowed without throwing.
     *
     * @return bool True if all guards allow execution, false otherwise
     */
    public function isAllowed(): bool
    {
        return array_all($this->getGuards(), fn ($guard): bool => $guard->shouldExecute());
    }

    /**
     * Get the first guard that blocks execution, or null if all pass.
     *
     * @return null|ExecutionGuard The blocking guard, or null if all pass
     */
    public function getBlockingGuard(): ?ExecutionGuard
    {
        foreach ($this->getGuards() as $guard) {
            if (!$guard->shouldExecute()) {
                return $guard;
            }
        }

        return null;
    }

    /**
     * Get all configured guard instances.
     *
     * Loads guards from configuration on first call and caches them.
     *
     * @return array<ExecutionGuard> Array of guard instances
     */
    public function getGuards(): array
    {
        if ($this->guards !== null) {
            return $this->guards;
        }

        $this->guards = [];

        /** @var array<array{driver: string, config?: array<string, mixed>}> $guardConfigs */
        $guardConfigs = config('sequencer.guards', []);

        foreach ($guardConfigs as $guardConfig) {
            $this->guards[] = $this->createGuard($guardConfig);
        }

        return $this->guards;
    }

    /**
     * Clear the cached guards (useful for testing).
     */
    public function clearCache(): void
    {
        $this->guards = null;
    }

    /**
     * Create a guard instance from configuration.
     *
     * @param array{driver: string, config?: array<string, mixed>} $guardConfig
     *
     * @throws InvalidArgumentException If driver class doesn't exist or doesn't implement ExecutionGuard
     */
    private function createGuard(array $guardConfig): ExecutionGuard
    {
        /** @var string $driver */
        $driver = $guardConfig['driver'];

        /** @var array<string, mixed> $config */
        $config = $guardConfig['config'] ?? [];

        if (!class_exists($driver)) {
            throw new InvalidArgumentException(
                sprintf("Guard class '%s' does not exist", $driver),
            );
        }

        if (!is_a($driver, ExecutionGuard::class, true)) {
            throw new InvalidArgumentException(
                sprintf("Guard class '%s' must implement %s", $driver, ExecutionGuard::class),
            );
        }

        return new $driver($config);
    }
}
