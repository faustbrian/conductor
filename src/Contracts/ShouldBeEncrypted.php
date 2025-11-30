<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Sequencer\Contracts;

/**
 * Marker interface for operations requiring encrypted serialization in queue storage.
 *
 * Operations implementing this interface have their entire payload automatically encrypted
 * before being serialized to the queue backend and decrypted when retrieved for execution.
 * This ensures sensitive data like API credentials, user tokens, personal information, or
 * confidential business data remains protected while stored in queue databases, Redis
 * instances, or other potentially shared storage systems.
 *
 * Encryption uses Laravel's built-in encryption facilities configured via the application
 * encryption key. The encryption and decryption process is transparent to the operation
 * implementation - the operation receives its unencrypted state when handle() executes.
 *
 * This interface provides defense-in-depth security for queued operations by ensuring
 * sensitive data is never stored in plaintext, even temporarily. It complements transport
 * layer security and database encryption by encrypting at the application layer before
 * serialization occurs.
 *
 * Performance considerations: encryption and decryption add minimal overhead but occur
 * on every queue push and pop operation. Use this interface selectively for operations
 * that genuinely handle sensitive data rather than applying it universally to all
 * queued operations.
 *
 * Common use cases:
 * - Operations containing API credentials or authentication tokens
 * - Payment processing with credit card or financial data
 * - User personally identifiable information (PII)
 * - Healthcare records or other regulated data
 * - Proprietary business information or trade secrets
 * - Temporary passwords or security codes
 *
 * ```php
 * final class ProcessPaymentWithStripe implements Operation, Asynchronous, ShouldBeEncrypted
 * {
 *     public function __construct(
 *         private readonly string $stripeToken,
 *         private readonly int $amountCents,
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         // Stripe token is encrypted while queued, decrypted before execution
 *         Stripe::charge($this->amountCents, $this->stripeToken);
 *     }
 * }
 * ```
 *
 * @api
 *
 * @author Brian Faust <brian@cline.sh>
 */
interface ShouldBeEncrypted
{
    // Marker interface - no methods required, presence triggers encryption
}
