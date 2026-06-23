<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Handler;

use Crehler\PaymentBundle\Application\Port\Driven\{OrderTransactionRepositoryInterface, PaymentSubMethodSessionResolverPort};
use Crehler\PaymentBundle\Application\Port\Driving\OrderTransactionServicePort;
use Crehler\PaymentBundle\Application\Service\StoredCardService;
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\PaymentBundle\Infrastructure\Handler\{AbstractPaymentMethodHandler, PaymentResult};
use Crehler\PaymentBundle\Shared\{EnhancedLogger, FinalizeTokenService, Serializer};
use Crehler\PayNow\Factory\TransactionDtoFactory;
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Crehler\PayNow\Services\IdempotencyKeyService;
use RuntimeException;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function is_string;

#[AutoconfigureTag('shopware.payment.method.async')]
final class CardHandler extends AbstractPaymentMethodHandler
{
    public function __construct(
        EnhancedLogger $logger,
        RouterInterface $router,
        OrderTransactionServicePort $orderTransactionServicePort,
        FinalizeTokenService $finalizeTokenService,
        PaymentSubMethodSessionResolverPort $paymentSubMethodSessionResolver,
        OrderTransactionRepositoryInterface $orderTransactionRepository,
        private readonly IdempotencyKeyService $idempotencyKeyService,
        private readonly TransactionDtoFactory $transactionDtoFactory,
        private readonly PayNowFactory $payNowFactory,
        private readonly StoredCardService $storedCardService,
    ) {
        parent::__construct(
            $logger,
            $router,
            $orderTransactionServicePort,
            $finalizeTokenService,
            $paymentSubMethodSessionResolver,
            $orderTransactionRepository,
        );
    }

    protected function getPaymentProviderName(): string
    {
        return 'PayNow Card';
    }

    protected function getProviderLogo(): string
    {
        return 'paynow';
    }

    protected function processPayment(
        Request $request,
        PaymentTransactionStruct $transaction,
        OrderTransaction $orderTransaction,
        ?string $paymentSubMethodId,
        Context $context,
    ): PaymentResult {
        // The request carries a LOCAL stored-card id, never a gateway token. Resolve it
        // through the ownership-scoped service so a customer can only use a card that
        // belongs to them on this sales channel; the gateway token is decrypted
        // server-side only after the ownership check passes (IDOR guard). A missing or
        // unowned id falls back to the new-card redirect flow (token stays null).
        $savedCardToken = $this->resolveSavedCardToken(
            $request->get('paynowCardToken'),
            $orderTransaction,
            $context,
        );

        // Get CARD payment method ID from PayNow API
        $cardPaymentMethodId = $this->getCardPaymentMethodId(
            currency: $orderTransaction->order->currencyCode,
            amount: $orderTransaction->totalAmount->amount,
            salesChannelId: $orderTransaction->order->salesChannelId,
        );

        if ($cardPaymentMethodId === null) {
            throw new RuntimeException('Unable to retrieve CARD payment method ID from PayNow API');
        }

        ['returnUrl' => $returnUrl] = $this->buildPaymentUrls($orderTransaction, $transaction);

        $idempotencyKey = $this->idempotencyKeyService->generate(
            orderTransaction: $orderTransaction,
            context: $context
        );

        // For card payments:
        // - paymentMethodId is the CARD payment method ID from PayNow API
        // - paymentMethodToken is the saved card token (if customer uses saved card)
        $transactionDto = $this->transactionDtoFactory->createTransactionDto(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            transactionId: $transaction->getOrderTransactionId(),
            paymentMethodId: $cardPaymentMethodId,
            paymentMethodToken: $savedCardToken,
        );

        $normalizedTransaction = Serializer::getSerializer()->normalize($transactionDto, 'json');

        $this->logger->debug('PayNow Card request prepared', [
            'orderTransactionId' => $transaction->getOrderTransactionId(),
            'usingSavedCard' => $savedCardToken !== null,
        ]);

        // Use the order's sales channel so the SDK picks per-channel credentials/environment
        // rather than the global default (sandbox/production split safety).
        $result = $this->payNowFactory
            ->payment($orderTransaction->order->salesChannelId)
            ->authorize($normalizedTransaction, $idempotencyKey);

        $this->logger->info('PayNow Card response', [
            'paymentId' => $result->getPaymentId(),
            'status' => $result->getStatus(),
        ]);

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $result->getPaymentId(), $context);

        return PaymentResult::success(
            redirectUrl: $result->getRedirectUrl() ?? '',
            gatewayOrderId: $result->getPaymentId()
        );
    }

    /**
     * Resolve a request-supplied stored-card id to its decrypted gateway token,
     * but only if the card belongs to the current customer and sales channel.
     * Returns null (→ new-card flow) when no id is supplied or ownership fails,
     * so a hostile/leaked id can never charge another customer's saved card.
     */
    private function resolveSavedCardToken(
        mixed $savedCardId,
        OrderTransaction $orderTransaction,
        Context $context,
    ): ?string {
        if (!is_string($savedCardId) || $savedCardId === '') {
            return null;
        }

        $card = $this->storedCardService->findByIdForCustomer(
            id: $savedCardId,
            customerId: $orderTransaction->order->customer->id,
            salesChannelId: $orderTransaction->order->salesChannelId,
            context: $context,
        );

        if ($card === null) {
            $this->logger->warning('PayNow: saved card not found or not owned by customer', [
                'orderTransactionId' => $orderTransaction->id,
            ]);

            return null;
        }

        $token = $this->storedCardService->decryptToken($card);

        return $token === '' ? null : $token;
    }

    /**
     * Get the CARD payment method ID from PayNow API.
     */
    private function getCardPaymentMethodId(string $currency, int $amount, ?string $salesChannelId = null): ?string
    {
        try {
            $paymentMethods = $this->payNowFactory->payment($salesChannelId)->getPaymentMethods($currency, $amount);
            $cardMethods = $paymentMethods->getOnlyCards();

            if (!empty($cardMethods)) {
                return (string) $cardMethods[0]->getId();
            }

            $this->logger->warning('No CARD payment method found in PayNow API');

            return null;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get CARD payment method ID from PayNow', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
