<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown when external resource has not been modified.
 *
 * Used when an API or external service indicates data has not changed since
 * the last sync (typically via HTTP 304 Not Modified response or ETag comparison).
 * Prevents unnecessary data processing and reduces API load.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ResourceNotModifiedException extends SkipOperationException
{
    /**
     * Create exception for unmodified resource.
     *
     * @return self Exception instance with not modified message
     */
    public static function create(): self
    {
        return new self('Resource not modified');
    }
}
