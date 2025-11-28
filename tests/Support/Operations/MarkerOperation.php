<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Support\Operations;

use Cline\Sequencer\Contracts\Operation;

use function file_put_contents;
use function storage_path;

/**
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class MarkerOperation implements Operation
{
    public function __construct(
        private string $marker,
    ) {}

    public function handle(): void
    {
        file_put_contents(storage_path('framework/testing/'.$this->marker.'_executed'), 'true');
    }
}
