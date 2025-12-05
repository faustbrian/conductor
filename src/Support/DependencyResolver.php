<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Support;

use Cline\Sequencer\Contracts\HasDependencies;
use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Database\Models\Operation as OperationModel;
use Cline\Sequencer\Exceptions\CircularDependencyException;
use Illuminate\Database\Migrations\Migrator;

use function array_all;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function preg_match;

/**
 * Resolves and validates operation dependencies to ensure safe execution order.
 *
 * Sequencer's dependency resolver provides topological sorting and validation for
 * operations with dependencies. Supports dependencies on both migrations and other
 * operations, prevents circular dependencies, and ensures all prerequisites are
 * satisfied before execution.
 *
 * ```php
 * $resolver = new DependencyResolver($migrator);
 *
 * // Check if dependencies are satisfied
 * if ($resolver->dependenciesSatisfied($operation)) {
 *     $operation->execute();
 * }
 *
 * // Sort tasks by dependencies
 * $sorted = $resolver->sortByDependencies($tasks);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class DependencyResolver
{
    /**
     * Create a new dependency resolver instance.
     *
     * @param Migrator $migrator Laravel's migration system for querying which migrations
     *                           have already been executed. Used to validate that migration
     *                           dependencies are satisfied before allowing operations to run,
     *                           enabling cross-dependency between migrations and operations.
     */
    public function __construct(
        private Migrator $migrator,
    ) {}

    /**
     * Check if all dependencies for an operation have been satisfied.
     *
     * Validates that all declared dependencies (both operations and migrations)
     * have been successfully executed. Operations without dependencies or those
     * not implementing HasDependencies always return true.
     *
     * @param  Operation $operation The operation instance to validate dependencies for
     * @return bool      True if all dependencies are satisfied or operation has no dependencies,
     *                   false if any required dependency has not been executed
     */
    public function dependenciesSatisfied(Operation $operation): bool
    {
        if (!$operation instanceof HasDependencies) {
            return true;
        }

        $dependencies = $operation->dependsOn();

        return array_all($dependencies, fn (string $dependency): bool => $this->isDependencySatisfied($dependency));
    }

    /**
     * Get list of unsatisfied dependencies for an operation.
     *
     * Returns dependency names that have not been executed, useful for debugging
     * and providing detailed error messages about missing prerequisites.
     *
     * @param  Operation    $operation The operation instance to check dependencies for
     * @return list<string> Array of dependency names (migrations or operations) that have not
     *                      been executed, or empty array if all dependencies are satisfied
     */
    public function getUnsatisfiedDependencies(Operation $operation): array
    {
        if (!$operation instanceof HasDependencies) {
            return [];
        }

        $dependencies = $operation->dependsOn();
        $unsatisfied = [];

        foreach ($dependencies as $dependency) {
            if ($this->isDependencySatisfied($dependency)) {
                continue;
            }

            $unsatisfied[] = $dependency;
        }

        return $unsatisfied;
    }

    /**
     * Sort tasks by dependencies using topological sort algorithm.
     *
     * Reorders tasks to ensure dependencies execute before dependents. Uses iterative
     * approach: repeatedly selects tasks whose dependencies are already sorted. Detects
     * circular dependencies when no progress can be made after a full iteration.
     *
     * Migrations are always processed immediately as they have no dependencies tracked
     * by this resolver. Operations are sorted based on their declared dependencies.
     *
     * @param list<array{type: string, timestamp: string, data: mixed, operation?: Operation}> $tasks
     *                                                                                                Array of pending tasks (migrations and operations) to sort by dependency order
     *
     * @throws CircularDependencyException When circular dependencies prevent completion
     *
     * @return list<array{type: string, timestamp: string, data: mixed, operation?: Operation}>
     *                                                                                          Tasks reordered to respect dependency constraints, ensuring safe execution order
     */
    public function sortByDependencies(array $tasks): array
    {
        /** @var list<array{type: string, timestamp: string, data: mixed, operation?: Operation}> $sorted */
        $sorted = [];
        $remaining = $tasks;
        $maxIterations = count($tasks) * 2;
        $iteration = 0;

        while ($remaining !== [] && $iteration < $maxIterations) {
            ++$iteration;
            $progressMade = false;

            foreach ($remaining as $key => $task) {
                if ($task['type'] !== 'operation') {
                    $sorted[] = $task;
                    unset($remaining[$key]);
                    $progressMade = true;

                    continue;
                }

                if (!array_key_exists('operation', $task)) {
                    /** @var array{class: string} $data */
                    $data = $task['data'];
                    $loadedOperation = require $data['class']; // 'class' is actually the file path

                    /** @var Operation $loadedOperation */
                    $task['operation'] = $loadedOperation;
                }

                /** @var Operation $operation */
                $operation = $task['operation'];

                if (!$this->areDependenciesInSorted($operation, $sorted)) {
                    continue;
                }

                /** @var array{type: string, timestamp: string, data: mixed, operation: Operation} $task */
                $sorted[] = $task;
                unset($remaining[$key]);
                $progressMade = true;
            }

            // Re-index array after all unsetting is complete
            $remaining = array_values($remaining);

            if (!$progressMade && $remaining !== []) {
                throw CircularDependencyException::detected();
            }
        }

        return $sorted;
    }

    /**
     * Check if a single dependency has been satisfied.
     *
     * Determines execution status by checking the appropriate source: migration
     * repository for migrations (identified by timestamp prefix) or operations
     * table for operations (completed_at column must be non-null).
     *
     * @param  string $dependency The dependency name (migration filename or operation class name)
     * @return bool   True if the dependency has been executed, false otherwise
     */
    private function isDependencySatisfied(string $dependency): bool
    {
        if ($this->isMigrationDependency($dependency)) {
            return in_array($dependency, $this->migrator->getRepository()->getRan(), true);
        }

        return OperationModel::query()
            ->where('name', $dependency)
            ->whereNotNull('completed_at')
            ->exists();
    }

    /**
     * Determine if a dependency is a migration based on naming convention.
     *
     * Migrations follow Laravel's timestamp naming pattern: YYYY_MM_DD_HHMMSS_description.php
     * Uses regex to detect the timestamp prefix that identifies migration files.
     *
     * @param  string $dependency The dependency name to check
     * @return bool   True if name matches migration pattern, false for operation dependencies
     */
    private function isMigrationDependency(string $dependency): bool
    {
        return (bool) preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $dependency);
    }

    /**
     * Check if all dependencies for an operation are in the sorted list.
     *
     * Validates an operation can be safely added to the sorted list by verifying
     * all declared dependencies are already present. Used during topological sort
     * to determine task readiness.
     *
     * @param  Operation                                                                        $operation The operation to validate
     * @param  list<array{type: string, timestamp: string, data: mixed, operation?: Operation}> $sorted    Tasks already sorted
     * @return bool                                                                             True if all dependencies are sorted or operation has none, false otherwise
     */
    private function areDependenciesInSorted(Operation $operation, array $sorted): bool
    {
        if (!$operation instanceof HasDependencies) {
            return true;
        }

        $dependencies = $operation->dependsOn();
        $sortedNames = array_map(function (array $task): string {
            if ($task['type'] === 'migration') {
                /** @var array{name: string, path: string} $data */
                $data = $task['data'];

                return $data['name'];
            }

            /** @var array{class: string} $data */
            $data = $task['data'];

            return $data['class'];
        }, $sorted);

        return array_all($dependencies, fn ($dependency): bool => in_array($dependency, $sortedNames, true));
    }
}
