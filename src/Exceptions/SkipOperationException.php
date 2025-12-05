<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

use RuntimeException;

/**
 * Base exception for gracefully skipping operation execution.
 *
 * Operations can throw exceptions extending this base to signal that execution
 * should be skipped without marking the operation as failed. Unlike ConditionalExecution
 * which makes skip decisions before execution begins, these exceptions allow runtime
 * decisions based on conditions discovered during execution.
 *
 * When thrown, the operation is marked as completed (not failed) and logged as skipped.
 *
 * Consumers can catch this base class to handle any skip scenario:
 *
 * ```php
 * try {
 *     $operation->handle();
 * } catch (SkipOperationException $e) {
 *     // Handle any skip scenario
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class SkipOperationException extends RuntimeException implements SequencerException
{
    // Abstract base - no factory methods
}
