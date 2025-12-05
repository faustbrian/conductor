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
 * Thrown when one or more operations fail during batch wave execution.
 *
 * In batch orchestration mode, operations are grouped into waves that execute
 * concurrently. This exception is raised when any operation in a wave fails,
 * preventing subsequent waves from executing. The exception includes both the
 * wave number and count of failed jobs to aid in debugging batch failures.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class WaveExecutionFailedException extends RuntimeException implements SequencerException
{
    /**
     * Create exception for failed batch wave execution.
     *
     * Thrown when a batch wave completes with one or more failed jobs. The batch
     * execution is halted and subsequent waves are not processed. The error message
     * includes the specific wave number and failure count for troubleshooting.
     *
     * @param  int  $waveNumber The sequential wave number that failed (1-indexed)
     * @param  int  $failedJobs The count of operations that failed in this wave
     * @return self Exception instance with wave details in error message
     */
    public static function forWave(int $waveNumber, int $failedJobs): self
    {
        return new self(
            sprintf('Wave %d failed with %d failed jobs', $waveNumber, $failedJobs),
        );
    }
}
