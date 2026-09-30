<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Services;

use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

use function hash;

final readonly class IdempotencyKeyService
{
    public function __construct(
        private EntityRepository $paynowIdempotencyKeyRepository,
    ) {
    }

    /**
     * Return the idempotency key for a payment ATTEMPT — not for the order transaction.
     *
     * PayNow dedupes authorize() calls carrying the same key, so a double-submit of the
     * same attempt (F5 before the redirect completes) MUST reuse the key, or the customer
     * gets charged twice. Keying on the transaction id alone went one step too far: when a
     * customer retries with the same Shopware payment method, Shopware reopens the SAME
     * order transaction instead of creating a new one (SetPaymentOrderRoute::tryTransition()),
     * so a switch from one bank to another reached the gateway under the original key and
     * PayNow replayed the original payment — the customer picked mBank and got ING (WT-918).
     *
     * The fingerprint below therefore covers everything that makes this a distinct attempt
     * from the customer's point of view. Identical inputs still yield an identical key, so
     * the double-charge guard is untouched; a changed sub-method yields a new key and a new
     * payment. The key is a pure function of its inputs, so parallel calls converge with no
     * read-before-write race to guard.
     *
     * The order amount is deliberately absent: Shopware already forces a brand-new
     * transaction when the amount changed (SetPaymentOrderRoute::hasChangedAmount()).
     */
    public function generate(
        OrderTransaction $orderTransaction,
        ?string $paymentSubMethodId = null,
        ?Context $context = null,
    ): string {
        if ($context === null) {
            $context = Context::createDefaultContext();
        }

        $fingerprint = $orderTransaction->id . '|' . ($paymentSubMethodId ?? 'default');

        // xxh128 only derives a stable, deterministic identifier from non-secret ids; it is
        // NOT used for security/hashing. 32 hex chars keeps the key inside PayNow's
        // Idempotency-Key length limit and gives the row a primary key IdField accepts.
        $idempotencyKey = 'paynow_' . hash('xxh128', $fingerprint);

        // Row kept as an audit trail of which attempt was sent under which key. The
        // deterministic primary key makes the upsert genuinely idempotent: parallel
        // requests for the same attempt target the same row and write identical data
        // (INSERT ... ON DUPLICATE KEY UPDATE) instead of inserting duplicates.
        $this->paynowIdempotencyKeyRepository->upsert([
            [
                'id' => hash('xxh128', 'paynow_idempotency|' . $fingerprint),
                'transactionId' => $orderTransaction->id,
                'idempotencyKey' => $idempotencyKey,
            ],
        ], $context);

        return $idempotencyKey;
    }
}
