<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Operations;

use Cline\Sequencer\Contracts\Operation;
use Cline\Sequencer\Exceptions\OperationFailedIntentionallyException;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class FailingOperation implements Operation
{
    public function handle(): void
    {
        throw OperationFailedIntentionallyException::create();
    }
}
