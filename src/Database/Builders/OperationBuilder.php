<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Database\Builders;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

use function str_contains;
use function today;

/**
 * Custom query builder for Operation model.
 *
 * Provides expressive, chainable query scopes for filtering operations
 * by their execution status, reducing verbosity and preventing breaking
 * changes if column names change in the future.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @template TModelClass of \Cline\Sequencer\Database\Models\Operation
 *
 * @extends Builder<TModelClass>
 */
final class OperationBuilder extends Builder
{
    /**
     * Scope query to executed operations.
     *
     * Filters operations that have been executed (executed_at is not null).
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function executed(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereNotNull('executed_at');
    }

    /**
     * Scope query to completed operations.
     *
     * Filters operations that have completed successfully (completed_at is not null).
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function completed(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereNotNull('completed_at');
    }

    /**
     * Scope query to failed operations.
     *
     * Filters operations that have failed (failed_at is not null).
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function failed(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereNotNull('failed_at');
    }

    /**
     * Scope query to rolled back operations.
     *
     * Filters operations that have been rolled back (rolled_back_at is not null).
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function rolledBack(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereNotNull('rolled_back_at');
    }

    /**
     * Scope query to skipped operations.
     *
     * Filters operations that were skipped during execution (skipped_at is not null).
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function skipped(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereNotNull('skipped_at');
    }

    /**
     * Scope query to pending operations.
     *
     * Filters operations that have been executed but not yet completed or failed.
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function pending(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereNotNull('executed_at')
            ->whereNull('completed_at')
            ->whereNull('failed_at');
    }

    /**
     * Scope query to synchronous operations.
     *
     * Filters operations that were executed synchronously (type = 'sync').
     *
     * @return $this
     */
    public function synchronous(): static
    {
        return $this->where('type', 'sync');
    }

    /**
     * Scope query to asynchronous operations.
     *
     * Filters operations that were executed asynchronously via queue (type = 'async').
     *
     * @return $this
     */
    public function asynchronous(): static
    {
        return $this->where('type', 'async');
    }

    /**
     * Scope query to operations by name.
     *
     * Filters operations matching a specific operation name or pattern.
     *
     * @param  string $name Operation name or wildcard pattern
     * @return $this
     */
    public function named(string $name): static
    {
        if (str_contains($name, '%')) {
            return $this->where('name', 'like', $name);
        }

        return $this->where('name', $name);
    }

    /**
     * Scope query to operations executed today.
     *
     * Filters operations that were executed today (based on executed_at timestamp).
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function today(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereDate('executed_at', today());
    }

    /**
     * Scope query to operations executed within date range.
     *
     * Filters operations executed between the specified start and end dates.
     *
     * @param  Carbon|DateTimeInterface|string $start Start date
     * @param  Carbon|DateTimeInterface|string $end   End date
     * @return $this
     *
     * @phpstan-return static
     */
    public function between(DateTimeInterface|string $start, DateTimeInterface|string $end): static
    {
        /** @phpstan-ignore-next-line */
        return $this->whereBetween('executed_at', [$start, $end]);
    }

    /**
     * Scope query to operations executed by specific entity.
     *
     * Filters operations executed by a specific polymorphic entity (User, System, etc.).
     *
     * @param  Model $executor The entity that executed the operation
     * @return $this
     */
    public function executedBy(Model $executor): self
    {
        return $this->where('executed_by_type', $executor->getMorphClass())
            ->where('executed_by_id', $executor->getKey());
    }

    /**
     * Scope query to operations with errors.
     *
     * Filters operations that have associated error records.
     *
     * @return $this
     */
    public function withErrors(): self
    {
        return $this->has('errors');
    }

    /**
     * Scope query to operations without errors.
     *
     * Filters operations that have no associated error records.
     *
     * @return $this
     */
    public function withoutErrors(): self
    {
        return $this->doesntHave('errors');
    }

    /**
     * Scope query to successful operations.
     *
     * Filters operations that completed successfully (either completed or skipped)
     * without being rolled back or failed. Skipped operations are considered
     * successful because the decision to skip was intentional and valid.
     *
     * @return $this
     *
     * @phpstan-return static
     */
    public function successful(): static
    {
        /** @phpstan-ignore-next-line */
        return $this->where(function (self $query): void {
            $query->whereNotNull('completed_at')
                ->orWhereNotNull('skipped_at');
        })->whereNull('rolled_back_at')
            ->whereNull('failed_at');
    }

    /**
     * Scope query to operations ordered by execution time.
     *
     * Orders operations by their execution timestamp.
     *
     * @param  string $direction Sort direction ('asc' or 'desc')
     * @return $this
     *
     * @phpstan-return static
     */
    public function orderedByExecution(string $direction = 'asc'): static
    {
        /** @phpstan-ignore-next-line */
        return $this->orderBy('executed_at', $direction);
    }

    /**
     * Scope query to most recent operations.
     *
     * Orders operations by most recent execution first. Overrides Eloquent's
     * default latest() behavior to always sort by executed_at instead of
     * created_at, providing consistent operation timeline ordering.
     *
     * @param  null|string $column Ignored - operations always ordered by executed_at
     * @return $this
     *
     * @phpstan-return $this
     */
    #[Override()]
    public function latest($column = null): static
    {
        /** @phpstan-ignore-next-line */
        return $this->orderedByExecution('desc');
    }

    /**
     * Scope query to oldest operations.
     *
     * Orders operations by oldest execution first. Overrides Eloquent's
     * default oldest() behavior to always sort by executed_at instead of
     * created_at, providing consistent operation timeline ordering.
     *
     * @param  null|string $column Ignored - operations always ordered by executed_at
     * @return $this
     *
     * @phpstan-return $this
     */
    #[Override()]
    public function oldest($column = null): static
    {
        /** @phpstan-ignore-next-line */
        return $this->orderedByExecution('asc');
    }
}
