<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Facades;

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Contracts\Orchestrator;
use Cline\Sequencer\Database\Models\OperationError;
use Cline\Sequencer\SequencerManager;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for programmatic operation execution and lifecycle management.
 *
 * Provides a high-level API for executing operations synchronously or asynchronously,
 * with support for multiple orchestration strategies (sequential, batch, transactional),
 * conditional execution, chaining, batching, rollback capabilities, and comprehensive
 * error tracking. Serves as the primary entry point for operation execution outside
 * of Artisan commands.
 *
 * @method static PendingBatch                                               batch(array<class-string<Operation>|string> $operations)
 * @method static PendingChain                                               chain(array<class-string<Operation>|string> $operations)
 * @method static void                                                       execute(class-string<Operation>|string $operation, bool $async = false, bool $record = true)
 * @method static void                                                       executeAll(bool $isolate = false, ?string $from = null, bool $repeat = false)
 * @method static void                                                       executeIf(bool $condition, class-string<Operation>|string $operation, bool $async = false, bool $record = true)
 * @method static void                                                       executeSync(class-string<Operation>|string $operation, bool $record = true)
 * @method static void                                                       executeUnless(bool $condition, class-string<Operation>|string $operation, bool $async = false, bool $record = true)
 * @method static Collection<int, OperationError>                            getErrors(class-string<Operation>|string $operation)
 * @method static bool                                                       hasExecuted(class-string<Operation>|string $operation)
 * @method static bool                                                       hasFailed(class-string<Operation>|string $operation)
 * @method static list<array{type: string, timestamp: string, name: string}> preview(?string $from = null, bool $repeat = false)
 * @method static void                                                       rollback(class-string<Operation>|string $operation, bool $record = true)
 * @method static SequencerManager                                           using(class-string<Orchestrator>|Orchestrator $orchestrator)
 *
 * @see SequencerManager
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class Sequencer extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The service container binding key for SequencerManager
     */
    protected static function getFacadeAccessor(): string
    {
        return SequencerManager::class;
    }
}
