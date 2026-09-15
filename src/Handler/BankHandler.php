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
use Crehler\PaymentBundle\Domain\Enum\PaymentType;
use Crehler\PaymentBundle\Infrastructure\Handler\{AbstractPaymentMethodHandler, PaymentResult};
use Crehler\PaymentBundle\Shared\{EnhancedLogger, FinalizeTokenService};
use Crehler\PayNow\Factory\TransactionDtoFactory;
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Crehler\PayNow\Services\IdempotencyKeyService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

#[AutoconfigureTag('shopware.payment.method.async')]
final class BankHandler extends AbstractPaymentMethodHandler
{
    use RejectsTerminalGatewayStatus;

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

    public static function paymentType(): PaymentType
    {
        return PaymentType::PAY_BY_LINK;
    }

    /** The PBL bank list comes from the gateway. */
    public static function usesGatewayChannels(): bool
    {
        return true;
    }

    protected function getPaymentProviderName(): string
    {
        return 'PayNow Bank';
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
        ['returnUrl' => $returnUrl] = $this->buildPaymentUrls($orderTransaction, $transaction);

        // The bank is part of the attempt: retrying with a different bank reuses the same
        // order transaction (Shopware reopens it), so without this the gateway would replay
        // the payment created for the previously chosen bank (WT-918).
        $idempotencyKey = $this->idempotencyKeyService->generate(
            orderTransaction: $orderTransaction,
            paymentSubMethodId: $paymentSubMethodId,
            context: $context
        );

        $transactionDto = $this->transactionDtoFactory->createTransactionDto(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            transactionId: $transaction->getOrderTransactionId(),
            paymentMethodId: $paymentSubMethodId
        );

        $normalizedTransaction = $this->transactionDtoFactory->normalize($transactionDto);

        // Use the order's sales channel so the SDK picks per-channel credentials/environment
        // rather than the global default (sandbox/production split safety).
        $result = $this->payNowFactory
            ->payment($orderTransaction->order->salesChannelId)
            ->authorize($normalizedTransaction, $idempotencyKey);

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $result->getPaymentId(), $context);

        // PayNow answers the authorization with a status, and until now nothing here read
        // it: a payment the gateway had already rejected came back as a success with no
        // redirect URL, which the shared flow reads as "accepted, the webhook will finish
        // it". For a rejected payment no webhook ever arrives.
        $rejection = $this->failIfGatewayRejected((string) $result->getStatus(), 'bank transfer');

        if ($rejection !== null) {
            return $rejection;
        }

        return PaymentResult::success(
            redirectUrl: $result->getRedirectUrl() ?? '',
            gatewayOrderId: $result->getPaymentId()
        );
    }
}
