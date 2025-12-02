<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Support\Operations;

use Cline\Sequencer\Contracts\Operation;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Exceptions\TestException;

use function throw_unless;

/**
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class SchemaCheckOperation implements Operation
{
    public function __construct(
        private string $tableName,
    ) {}

    public function handle(): void
    {
        throw_unless(Schema::hasTable($this->tableName), TestException::migrationNotRun($this->tableName));
    }
}
