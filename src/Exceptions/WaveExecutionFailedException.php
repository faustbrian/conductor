<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

use function sprintf;

/**
 * @author Brian Faust <brian@cline.sh>
 */
final class WaveExecutionFailedException extends RuntimeException
{
    public static function forWave(int $waveNumber, int $failedJobs): self
    {
        return new self(
            sprintf('Wave %d failed with %d failed jobs', $waveNumber, $failedJobs),
        );
    }
}
