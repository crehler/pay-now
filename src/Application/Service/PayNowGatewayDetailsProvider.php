<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Application\Service;

use Crehler\PaymentBundle\Application\DTO\GatewayDetails\{GatewayPaymentDetails, GatewayStatusLevel};
use Crehler\PaymentBundle\Application\Port\Driven\GatewayPaymentDetailsProviderInterface;
use Crehler\PaymentBundle\Domain\Constant\PaymentCustomFields;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\PayNow\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Throwable;

use function in_array;
use function strtoupper;

/**
 * Exposes PayNow payment details for the admin order "Szczegóły" tab.
 *
 * PayNow's status endpoint only returns the payment id and status; amount/currency
 * are enriched from the Shopware order transaction by the controller, and the other
 * fields stay null (the UI renders "—").
 */
final readonly class PayNowGatewayDetailsProvider implements GatewayPaymentDetailsProviderInterface
{
    /**
     * @var mixed[]
     */
    private const HANDLERS = [BlikHandler::class, BankHandler::class, CardHandler::class];

    public function __construct(
        private PayNowFactory $payNowFactory,
        private EnhancedLogger $logger,
    ) {
    }

    public function supports(OrderTransactionEntity $orderTransaction): bool
    {
        return in_array($orderTransaction->getPaymentMethod()?->getHandlerIdentifier(), self::HANDLERS, true);
    }

    public function getDetails(OrderTransactionEntity $orderTransaction): ?GatewayPaymentDetails
    {
        $customFields = $orderTransaction->getCustomFields() ?? [];
        $gatewayId = $customFields[PaymentCustomFields::GATEWAY_PAYMENT_ID] ?? null;
        if (!$gatewayId) {
            return null;
        }

        try {
            $salesChannelId = $orderTransaction->getOrder()?->getSalesChannelId();
            $status = $this->payNowFactory->payment($salesChannelId)->status((string) $gatewayId);
            $rawStatus = (string) $status->getStatus();

            return new GatewayPaymentDetails(
                provider: 'PayNow',
                gatewayId: (string) ($status->getPaymentId() ?: $gatewayId),
                rawStatus: $rawStatus,
                statusLevel: $this->mapLevel($rawStatus),
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to load PayNow gateway details', [
                'orderTransactionId' => $orderTransaction->getId(),
                'gatewayId' => $gatewayId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function mapLevel(string $status): string
    {
        // Keep this set aligned with PaymentNotificationSubscriber::mapStatus and
        // PayNowPaymentStatusProvider so the details view never disagrees with the
        // status that actually transitions the order (CANCEL is a failure here too).
        return match (strtoupper($status)) {
            'CONFIRMED' => GatewayStatusLevel::PAID->value,
            'NEW', 'PENDING' => GatewayStatusLevel::PENDING->value,
            'REJECTED', 'CANCEL', 'ERROR', 'EXPIRED', 'ABANDONED' => GatewayStatusLevel::FAILED->value,
            default => GatewayStatusLevel::UNKNOWN->value,
        };
    }
}
