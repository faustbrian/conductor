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
use function str_replace;
use function throw_if;

/**
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class OperationWithDependency implements HasDependencies, Operation
{
    public function __construct(
        private string $dependency,
        private ?string $marker = null,
    ) {}

    public function handle(): void
    {
        if ($this->marker !== null) {
            $markerFile = storage_path('framework/testing/'.$this->marker);
            throw_if($this->dependency && !file_exists(storage_path('framework/testing/'.str_replace(['2024_01_01_000000_', '2024_01_01_000001_', '.php'], ['', '', ''], $this->dependency).'_executed')), TestException::simulatedFailure('Dependency did not execute first'));

            file_put_contents($markerFile.'_executed', 'true');
        }
    }

    public function dependsOn(): array
    {
        return $this->dependency !== '' && $this->dependency !== '0' ? [$this->dependency] : [];
    }
}
