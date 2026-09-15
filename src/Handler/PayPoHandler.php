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
use Crehler\PayNow\Constant\Methods;
use Crehler\PayNow\Factory\TransactionDtoFactory;
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Crehler\PayNow\Services\IdempotencyKeyService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Buy now, pay later through PayNow's White Label integration.
 *
 * Mechanically this is the bank transfer with one field changed — same endpoint, same
 * redirect, same webhook — but the value of that field is fixed rather than chosen. The
 * customer picks PayPo as the payment METHOD, so there is nothing left to choose at the
 * channel level, and the id PayNow expects is a documented constant.
 *
 * What PayPo needs beyond an ordinary payment is the buyer's address, both billing and
 * shipping. The plugin sends that on every payment now, so nothing method-specific happens
 * here; if a shop's order somehow carries neither, PayNow declines the payment rather than
 * this handler guessing an address.
 */
#[AutoconfigureTag('shopware.payment.method.async')]
final class PayPoHandler extends AbstractPaymentMethodHandler
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
        return PaymentType::DEFERRED;
    }

    /**
     * PayPo arrives from the gateway as its own type, never inside the PBL group, so there
     * is no channel list to draw and nothing for the customer to pick.
     */
    public static function usesGatewayChannels(): bool
    {
        return false;
    }

    protected function getPaymentProviderName(): string
    {
        return 'PayNow PayPo';
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

        // No channel is part of the attempt here — the method IS the choice — so the key
        // varies only with the transaction, unlike the bank handler where retrying with a
        // different bank has to produce a different key (WT-918).
        $idempotencyKey = $this->idempotencyKeyService->generate(
            orderTransaction: $orderTransaction,
            paymentSubMethodId: null,
            context: $context
        );

        $transactionDto = $this->transactionDtoFactory->createTransactionDto(
            orderTransaction: $orderTransaction,
            returnUrl: $returnUrl,
            transactionId: $transaction->getOrderTransactionId(),
            paymentMethodId: Methods::PAYPO_GATEWAY_ID
        );

        $normalizedTransaction = $this->transactionDtoFactory->normalize($transactionDto);

        // Use the order's sales channel so the SDK picks per-channel credentials/environment
        // rather than the global default (sandbox/production split safety).
        $result = $this->payNowFactory
            ->payment($orderTransaction->order->salesChannelId)
            ->authorize($normalizedTransaction, $idempotencyKey);

        $this->persistGatewayPaymentId($transaction->getOrderTransactionId(), $result->getPaymentId(), $context);

        // BNPL is the case where this matters most: PayPo underwrites the buyer, so a
        // decline at authorization time is an ordinary outcome rather than an edge case,
        // and without this check every declined application looked like a successful
        // payment waiting for a webhook that was never going to come.
        $rejection = $this->failIfGatewayRejected((string) $result->getStatus(), 'PayPo');

        if ($rejection !== null) {
            return $rejection;
        }

        return PaymentResult::success(
            redirectUrl: $result->getRedirectUrl() ?? '',
            gatewayOrderId: $result->getPaymentId()
        );
    }
}
