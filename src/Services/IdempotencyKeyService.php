<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Services;

use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

use function md5;

final readonly class IdempotencyKeyService
{
    public function __construct(
        private EntityRepository $paynowIdempotencyKeyRepository,
    ) {
    }

    /**
     * Return a stable idempotency key for the order transaction. PayNow dedupes
     * authorize() calls that carry the same key, so a retry/double-submit of the
     * same payment attempt MUST reuse the key — otherwise a fresh random key would
     * be treated as a new payment and double-charge the customer. The first call
     * persists a key for the transaction; every later call reads it back.
     */
    public function generate(OrderTransaction $orderTransaction, ?Context $context = null): string
    {
        if ($context === null) {
            $context = Context::createDefaultContext();
        }

        $existing = $this->findExistingKey($orderTransaction->id, $context);
        if ($existing !== null) {
            return $existing;
        }

        $idempotencyKey = 'paynow_' . $orderTransaction->id;

        // Both the key and the row are fully derived from the transaction id, so deriving
        // the primary key deterministically too makes the upsert genuinely idempotent:
        // parallel requests that all miss findExistingKey() above target the same PK and
        // write identical data (INSERT ... ON DUPLICATE KEY UPDATE), so the TOCTOU window
        // can no longer produce duplicate rows. A random id would insert a second row.
        $this->paynowIdempotencyKeyRepository->upsert([
            [
                // md5() here only derives a stable, deterministic primary key from the
                // transaction id; it is NOT used for security/hashing (CWE-328 N/A).
                'id' => md5('paynow_idempotency_' . $orderTransaction->id),
                'transactionId' => $orderTransaction->id,
                'idempotencyKey' => $idempotencyKey,
            ],
        ], $context);

        return $idempotencyKey;
    }

    private function findExistingKey(string $transactionId, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactionId', $transactionId));
        $criteria->setLimit(1);

        $record = $this->paynowIdempotencyKeyRepository->search($criteria, $context)->first();

        return $record?->get('idempotencyKey');
    }
}
