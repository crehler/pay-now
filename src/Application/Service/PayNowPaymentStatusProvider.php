<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Application\Service;

use Crehler\PaymentBundle\Application\Port\Driven\PaymentGatewayStatusProviderInterface;
use Crehler\PaymentBundle\Domain\Constant\PaymentCustomFields;
use Crehler\PaymentBundle\Domain\ValueObjects\PaymentStatus;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\PayNow\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

use function in_array;
use function strtoupper;

#[AutoconfigureTag('crehler.payment.gateway_status_provider')]
final readonly class PayNowPaymentStatusProvider implements PaymentGatewayStatusProviderInterface
{
    /**
     * @var mixed[]
     */
    private const PAYNOW_HANDLER_IDENTIFIERS = [
        BlikHandler::class,
        BankHandler::class,
        CardHandler::class,
    ];

    public function __construct(
        private PayNowFactory $payNowFactory,
        private EnhancedLogger $logger,
    ) {
    }

    public function supports(OrderTransactionEntity $orderTransaction): bool
    {
        $paymentMethod = $orderTransaction->getPaymentMethod();

        if ($paymentMethod === null) {
            return false;
        }

        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

        return in_array($handlerIdentifier, self::PAYNOW_HANDLER_IDENTIFIERS, true);
    }

    public function getPaymentStatus(OrderTransactionEntity $orderTransaction): ?PaymentStatus
    {
        $customFields = $orderTransaction->getCustomFields();
        $gatewayPaymentId = $customFields[PaymentCustomFields::GATEWAY_PAYMENT_ID] ?? null;

        if ($gatewayPaymentId === null) {
            $this->logger->warning('PayNow gateway payment ID not found in transaction custom fields', [
                'orderTransactionId' => $orderTransaction->getId(),
            ]);

            return null;
        }

        try {
            $salesChannelId = $orderTransaction->getOrder()?->getSalesChannelId();
            $statusResponse = $this->payNowFactory->payment($salesChannelId)->status($gatewayPaymentId);
            $status = (string) $statusResponse->getStatus();

            $this->logger->info('PayNow payment status retrieved', [
                'orderTransactionId' => $orderTransaction->getId(),
                'gatewayPaymentId' => $gatewayPaymentId,
                'status' => $status,
            ]);

            return $this->mapPayNowStatus(status: $status, orderTransactionId: $orderTransaction->getId());
        } catch (Throwable $e) {
            $this->logger->error('Failed to get PayNow payment status', [
                'orderTransactionId' => $orderTransaction->getId(),
                'gatewayPaymentId' => $gatewayPaymentId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Map a PayNow payment status to a canonical PaymentStatus.
     *
     * Unknown statuses return null so the caller falls back to the Shopware
     * state machine instead of forcing the paid/waiting/failed triad.
     */
    private function mapPayNowStatus(string $status, string $orderTransactionId): ?PaymentStatus
    {
        return match (strtoupper($status)) {
            'CONFIRMED' => PaymentStatus::paid(),
            'NEW', 'PENDING' => PaymentStatus::waiting(),
            'REJECTED', 'ERROR', 'EXPIRED', 'ABANDONED', 'CANCEL' => PaymentStatus::failed(),
            default => $this->logUnknownAndDeferToStateMachine($status, $orderTransactionId),
        };
    }

    private function logUnknownAndDeferToStateMachine(string $status, string $orderTransactionId): ?PaymentStatus
    {
        $this->logger->warning('PayNow returned unmapped transaction status; deferring to state machine', [
            'orderTransactionId' => $orderTransactionId,
            'status' => $status,
        ]);

        return null;
    }
}
