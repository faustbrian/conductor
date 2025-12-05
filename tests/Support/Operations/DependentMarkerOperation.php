<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Support\Operations;

use Cline\Sequencer\Contracts\HasDependencies;
use Cline\Sequencer\Contracts\Operation;
use Tests\Support\Exceptions\TestException;

use function file_exists;
use function file_put_contents;
use function storage_path;

/**
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class DependentMarkerOperation implements HasDependencies, Operation
{
    public function __construct(
        private string $marker,
        private array $dependencies,
        private ?string $checkMarker = null,
    ) {}

    public function handle(): void
    {
        if ($this->checkMarker && !file_exists(storage_path('framework/testing/'.$this->checkMarker.'_executed'))) {
            throw TestException::dependencyNotExecuted($this->checkMarker, $this->marker);
        }

        file_put_contents(storage_path('framework/testing/'.$this->marker.'_executed'), 'true');
    }

    public function dependsOn(): array
    {
        return $this->dependencies;
    }
}
