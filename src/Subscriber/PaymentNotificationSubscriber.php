<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Subscriber;

use Crehler\PaymentBundle\Application\Service\TransactionStateApplier;
use Crehler\PaymentBundle\Domain\Constant\PaymentCustomFields;
use Crehler\PaymentBundle\Domain\Event\PaymentNotificationReceivedEvent;
use Crehler\PaymentBundle\Domain\ValueObjects\TransactionStateTransition;
use Crehler\PaymentBundle\Infrastructure\Subscriber\AbstractPaymentNotificationSubscriber;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\PayNow\Enums\PayNowOrderStatusesEnum;
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Throwable;

final class PaymentNotificationSubscriber extends AbstractPaymentNotificationSubscriber
{
    /**
     * Order transaction resolved during verify() so the per-sales-channel signature
     * key is selected before the SDK signature check, and reused by
     * resolveOrderTransaction() without a second lookup. Per-request, reset on supports().
     */
    private ?OrderTransactionEntity $resolvedOrderTransaction = null;

    public function __construct(
        TransactionStateApplier $transactionStateApplier,
        EnhancedLogger $logger,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly PayNowFactory $payNowFactory,
    ) {
        parent::__construct($transactionStateApplier, $logger);
    }

    /**
     * PayNow notifications carry paymentId/externalId/status in the body and a
     * Signature header for verification.
     */
    protected function supports(PaymentNotificationReceivedEvent $event): bool
    {
        // Reset the per-request cache so a previous notification's transaction can
        // never leak into this one (subscribers are shared services).
        $this->resolvedOrderTransaction = null;

        $payload = $event->getPayload();
        $headers = $event->getHeaders();

        $hasRequiredFields = isset($payload['paymentId'], $payload['externalId'], $payload['status']);
        $hasSignature = isset($headers['signature']) || isset($headers['Signature']);

        return $hasRequiredFields && $hasSignature;
    }

    protected function verify(PaymentNotificationReceivedEvent $event): bool
    {
        // Resolve the transaction first so the signature is verified with the
        // resolved transaction's sales-channel signature key (multi-channel installs
        // may configure per-channel credentials). The transaction is cached and
        // reused by resolveOrderTransaction() — no second lookup.
        $orderTransaction = $this->lookupOrderTransaction($event, $event->context);
        if ($orderTransaction === null) {
            // No transaction → cannot pick the right key. Fail closed; the base maps a
            // failed verify() to HTTP 400, and a missing transaction would 404 anyway.
            $this->logger->warning('PayNow: notification verification skipped, order transaction not resolved');

            return false;
        }

        $this->resolvedOrderTransaction = $orderTransaction;
        $salesChannelId = $orderTransaction->getOrder()?->getSalesChannelId();

        try {
            $this->payNowFactory->notification(
                salesChannelId: $salesChannelId,
                payload: (string) $event->request->getContent(),
                headers: $event->getHeaders(),
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->error('PayNow notification signature verification failed', ['exception' => $e]);

            return false;
        }
    }

    protected function resolveOrderTransaction(
        PaymentNotificationReceivedEvent $event,
        Context $context,
    ): ?OrderTransactionEntity {
        // Already resolved (and signature-verified against its channel) in verify().
        return $this->resolvedOrderTransaction;
    }

    protected function mapStatus(
        PaymentNotificationReceivedEvent $event,
        OrderTransactionEntity $orderTransaction,
    ): TransactionStateTransition {
        $status = (string) ($event->getPayload()['status'] ?? '');

        return match (PayNowOrderStatusesEnum::tryFrom($status)) {
            PayNowOrderStatusesEnum::CONFIRMED => TransactionStateTransition::PAID,
            PayNowOrderStatusesEnum::REJECTED,
            PayNowOrderStatusesEnum::CANCEL,
            PayNowOrderStatusesEnum::ERROR => TransactionStateTransition::CANCELLED,
            default => TransactionStateTransition::NONE,
        };
    }

    private function lookupOrderTransaction(
        PaymentNotificationReceivedEvent $event,
        Context $context,
    ): ?OrderTransactionEntity {
        $payload = $event->getPayload();
        $externalId = (string) ($payload['externalId'] ?? '');
        $paymentId = (string) ($payload['paymentId'] ?? '');

        // Primary: externalId is the order transaction id (sent as externalId in the DTO).
        if ($externalId !== '') {
            $criteria = new Criteria([$externalId]);
            $criteria->addAssociation('order.orderCustomer');

            /** @var OrderTransactionEntity|null $orderTransaction */
            $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

            if ($orderTransaction !== null && $this->paymentIdMatches($orderTransaction, $paymentId)) {
                return $orderTransaction;
            }
        }

        // Fallback: by the PayNow payment id stored on custom fields.
        if ($paymentId !== '') {
            $criteria = new Criteria();
            $criteria->addAssociation('order.orderCustomer');
            $criteria->addFilter(new EqualsFilter('customFields.' . PaymentCustomFields::GATEWAY_PAYMENT_ID, $paymentId));

            /** @var OrderTransactionEntity|null $orderTransaction */
            $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

            if ($orderTransaction !== null) {
                $this->logger->info('PayNow: order transaction found via gatewayPaymentId fallback', [
                    'paymentId' => $paymentId,
                    'orderTransactionId' => $orderTransaction->getId(),
                ]);

                return $orderTransaction;
            }
        }

        // Final fallback: treat externalId as the order number and take the latest transaction.
        // An order may carry several payment attempts, so still bind the notification to a
        // transaction whose stored gateway payment id matches (paymentIdMatches() accepts a
        // not-yet-stored id) — otherwise we could mark the wrong attempt as paid/cancelled.
        $orderTransaction = $externalId !== '' ? $this->resolveByOrderNumber($externalId, $context) : null;

        if ($orderTransaction !== null && !$this->paymentIdMatches($orderTransaction, $paymentId)) {
            $orderTransaction = null;
        }

        if ($orderTransaction === null) {
            $this->logger->error('PayNow: order transaction not found', [
                'externalId' => $externalId,
                'paymentId' => $paymentId,
            ]);
        }

        return $orderTransaction;
    }

    /**
     * Bind the notification's paymentId to the resolved transaction: when the
     * transaction already carries a stored gateway payment id, a notification whose
     * paymentId differs must not be applied (it belongs to a different payment).
     * If no gateway id is stored yet, accept (first notification of the lifecycle).
     */
    private function paymentIdMatches(OrderTransactionEntity $orderTransaction, string $paymentId): bool
    {
        $storedPaymentId = $orderTransaction->getCustomFields()[PaymentCustomFields::GATEWAY_PAYMENT_ID] ?? null;

        if ($storedPaymentId === null || $storedPaymentId === '') {
            return true;
        }

        if ((string) $storedPaymentId === $paymentId) {
            return true;
        }

        $this->logger->warning('PayNow: notification paymentId does not match stored gateway payment id', [
            'orderTransactionId' => $orderTransaction->getId(),
            'notificationPaymentId' => $paymentId,
        ]);

        return false;
    }

    private function resolveByOrderNumber(string $orderNumber, Context $context): ?OrderTransactionEntity
    {
        $orderCriteria = new Criteria();
        $orderCriteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($orderCriteria, $context)->first();

        if ($order === null) {
            return null;
        }

        $transactionCriteria = new Criteria();
        $transactionCriteria->addAssociation('order.orderCustomer');
        $transactionCriteria->addFilter(new EqualsFilter('orderId', $order->getId()));
        $transactionCriteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $transactionCriteria->setLimit(1);

        return $this->orderTransactionRepository->search($transactionCriteria, $context)->first();
    }
}
