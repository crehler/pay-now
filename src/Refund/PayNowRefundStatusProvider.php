<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Refund;

use Crehler\PaymentBundle\Application\Port\Driven\RefundStatusProviderPort;
use Crehler\PaymentBundle\Domain\ValueObjects\RefundStatus;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\PayNowPayment\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\PayNowPayment\Infrastructure\Client\PayNowFactory;
use Paynow\Model\Refund\Status;
use Shopware\Core\Framework\Context;
use Throwable;

use function in_array;
use function strtoupper;

/**
 * Answers "what is the state of this refund at PayNow right now".
 *
 * PayNow accepts a refund as PENDING and sends no notification when it settles, so a
 * refund recorded in the shop stayed "in progress" forever while the money had already
 * moved (WT-910). The bundle's PendingRefundFinalizer polls this on its scheduled run;
 * everything about Shopware state handling stays there.
 *
 * The gateway does expose a per-refund status endpoint (GET /v3/refunds/{id}/status),
 * so unlike Tpay there is no need to list refunds over a time window — each refund is
 * asked about directly by the id stored as the refund's externalReference.
 */
final class PayNowRefundStatusProvider implements RefundStatusProviderPort
{
    /**
     * @var mixed[]
     */
    private const SUPPORTED_HANDLERS = [
        BankHandler::class,
        BlikHandler::class,
        CardHandler::class,
    ];

    public function __construct(
        private readonly PayNowFactory $payNowFactory,
        private readonly EnhancedLogger $logger,
    ) {
    }

    public function supports(string $handlerIdentifier): bool
    {
        return in_array($handlerIdentifier, self::SUPPORTED_HANDLERS, true);
    }

    public function getRefundStatus(
        string $gatewayRefundId,
        ?string $salesChannelId,
        Context $context,
    ): ?RefundStatus {
        if ($gatewayRefundId === '') {
            return null;
        }

        try {
            $status = $this->payNowFactory
                ->refund($salesChannelId)
                ->status($gatewayRefundId);
        } catch (Throwable $e) {
            // The port forbids throwing: one unreachable account may not abort the batch.
            $this->logger->warning('Could not read PayNow refund status', [
                'gatewayRefundId' => $gatewayRefundId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->mapStatus((string) $status->getStatus(), $gatewayRefundId);
    }

    /**
     * Kept aligned with PayNowRefundProvider::refund(), which maps the same PayNow
     * statuses when the refund is first created — the two must never disagree about
     * what SUCCESSFUL or PENDING means.
     *
     * An unmapped status returns null ("ask again later") rather than a guess: treating
     * it as failed would mark a refund the gateway may still be processing as broken.
     */
    private function mapStatus(string $payNowStatus, string $gatewayRefundId): ?RefundStatus
    {
        return match (strtoupper($payNowStatus)) {
            Status::SUCCESSFUL => RefundStatus::COMPLETED,
            Status::PENDING, Status::NEW => RefundStatus::IN_PROGRESS,
            Status::FAILED, Status::CANCELLED => RefundStatus::FAILED,
            default => $this->logUnmapped($payNowStatus, $gatewayRefundId),
        };
    }

    private function logUnmapped(string $payNowStatus, string $gatewayRefundId): ?RefundStatus
    {
        $this->logger->warning('PayNow returned an unmapped refund status; will retry next run', [
            'gatewayRefundId' => $gatewayRefundId,
            'status' => $payNowStatus,
        ]);

        return null;
    }
}
