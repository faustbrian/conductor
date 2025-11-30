<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Support\Operations;

use Cline\Sequencer\Contracts\Operation;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class SimpleTestOperation implements Operation
{
    public function handle(): void
    {
        // Simpletest operation that does nothing
    }
}
