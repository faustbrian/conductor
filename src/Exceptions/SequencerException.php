<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use Throwable;

/**
 * Marker interface for all Sequencer package exceptions.
 *
 * Consumers can catch this interface to handle any exception thrown by the
 * Sequencer package with a single catch block.
 *
 * ```php
 * use Cline\Sequencer\Exceptions\SequencerException;
 *
 * try {
 *     $sequencer->execute();
 * } catch (SequencerException $e) {
 *     // Handle any Sequencer error
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface SequencerException extends Throwable
{
    // Marker interface - no methods required
}
