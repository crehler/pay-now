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
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\PaymentBundle\Infrastructure\Handler\{AbstractPaymentMethodHandler, PaymentResult};
use Crehler\PaymentBundle\Shared\{EnhancedLogger, FinalizeTokenService, Serializer};
use Crehler\PayNow\Factory\TransactionDtoFactory;
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Crehler\PayNow\Services\IdempotencyKeyService;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\{RedirectResponse, Request};
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function is_string;

#[AutoconfigureTag('shopware.payment.method.async')]
final class BlikHandler extends AbstractPaymentMethodHandler
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

    /**
     * Returns false for non-refund handler types on purpose: BLIK is layer-zero and is
     * driven through pay() → payViaBlikAuthorize() (an in-place authorize on the BLIK
     * code), never Shopware's standard async charge path, so it must not advertise
     * support for those types. REFUND is the one exception and is delegated to the base
     * handler so a BLIK order stays refundable via the registered RefundProviderPort.
     */
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // BLIK payment is layer-zero (driven through pay(), never Shopware's async path),
        // but a BLIK order is still refundable — defer REFUND to the base handler so it
        // resolves the registered RefundProviderPort instead of reporting "unsupported".
        if ($type === PaymentHandlerType::REFUND) {
            return parent::supports($type, $paymentMethodId, $context);
        }

        return false;
    }

    /**
     * BLIK uses the shared layer-zero flow: authorize in-place on a BLIK code,
     * otherwise redirect to the PayNow payment page.
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct,
    ): ?RedirectResponse {
        return $this->payViaBlikAuthorize($request, $transaction, $context);
    }

    protected function getPaymentProviderName(): string
    {
        return 'PayNow BLIK';
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
        // $request->get() returns mixed: a crafted payload (e.g. blikCode[]=x) would
        // hand an array to the ?string DTO param and trigger a TypeError. Normalize to
        // string|null up front, mirroring CardHandler's request-value guard.
        $authorizationCode = $request->get('blikCode');
        $authorizationCode = is_string($authorizationCode) && $authorizationCode !== '' ? $authorizationCode : null;

        $this->logger->info('BLIK payment processing', [
            'hasAuthorizationCode' => !empty($authorizationCode),
            'orderTransactionId' => $transaction->getOrderTransactionId(),
        ]);

        ['returnUrl' => $returnUrl] = $this->buildPaymentUrls($orderTransaction, $transaction);

        $idempotencyKey = $this->idempotencyKeyService->generate(
            orderTransaction: $orderTransaction,
            context: $context
        );

        // To authorize a BLIK on a T6 code, PayNow needs BOTH the BLIK payment-method id
        // and the code. Without the id PayNow can't tell it's a BLIK code payment and
        // falls back to a paywall redirect (status NEW), so the code is ignored.
        $paymentMethodId = empty($authorizationCode)
            ? null
            : $this->resolveBlikPaymentMethodId($orderTransaction);

        $transactionDto = $this->transactionDtoFactory->createTransactionDto(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            transactionId: $transaction->getOrderTransactionId(),
            paymentMethodId: $paymentMethodId,
            authorizationCode: $authorizationCode,
        );

        $normalizedTransaction = Serializer::getSerializer()->normalize($transactionDto, 'json');

        $this->logger->debug('PayNow BLIK request prepared', [
            'orderTransactionId' => $transaction->getOrderTransactionId(),
            'hasAuthorizationCode' => !empty($authorizationCode),
        ]);

        // Use the order's sales channel so the SDK picks per-channel credentials/environment
        // (consistent with resolveBlikPaymentMethodId), rather than the global default.
        $result = $this->payNowFactory
            ->payment($orderTransaction->order->salesChannelId)
            ->authorize($normalizedTransaction, $idempotencyKey);

        $this->logger->info('PayNow BLIK response', [
            'paymentId' => $result->getPaymentId(),
            'status' => $result->getStatus(),
        ]);

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $result->getPaymentId(), $context);

        // A BLIK authorized on a T6 code has no redirect (PENDING, redirectUrl null);
        // empty string = "no redirect" → the shared flow sends the customer to the
        // in-shop authorize/polling page instead of the gateway paywall.
        // Exceptions propagate to the base payViaBlikAuthorize() try-catch, which logs
        // (critical, with orderTransactionId) and rethrows — no local catch needed.
        return PaymentResult::success(
            redirectUrl: $result->getRedirectUrl() ?? '',
            gatewayOrderId: $result->getPaymentId()
        );
    }

    /**
     * Resolve PayNow's BLIK payment-method id (from the gateway's enabled methods)
     * so a T6 code can be authorized in-place. Returns null if it can't be resolved,
     * in which case the flow degrades to the redirect behaviour.
     */
    private function resolveBlikPaymentMethodId(OrderTransaction $orderTransaction): ?string
    {
        try {
            $methods = $this->payNowFactory
                ->payment($orderTransaction->order->salesChannelId)
                ->getPaymentMethods(
                    currency: $orderTransaction->order->currencyCode,
                    amount: $orderTransaction->totalAmount->amount,
                    applePayEnabled: true,
                );

            foreach ($methods->getOnlyBlik() as $blik) {
                return (string) $blik->getId();
            }
        } catch (Throwable $e) {
            $this->logger->warning('PayNow: could not resolve BLIK payment method id', [
                'exception' => $e->getMessage(),
                'orderTransactionId' => $orderTransaction->id,
            ]);
        }

        return null;
    }
}
