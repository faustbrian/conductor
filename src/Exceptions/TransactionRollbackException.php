<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Exceptions;

/**
 * Thrown to trigger database transaction rollback.
 *
 * Used in transactional operations to signal that all database changes within
 * the current transaction should be rolled back. Ensures data consistency when
 * partial operation completion would leave the system in an invalid state.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class TransactionRollbackException extends OperationFailedException
{
    /**
     * Create exception to trigger transaction rollback.
     *
     * @return self Exception instance with transaction rollback message
     */
    public static function create(): self
    {
        return new self('Transaction should rollback');
    }
}
