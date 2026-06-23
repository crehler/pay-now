<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Refund;

use Crehler\PaymentBundle\Application\DTO\Refund\{RefundCommand, RefundReason};
use Crehler\PaymentBundle\Application\Port\Driven\{RefundProviderPort, RefundReasonProviderInterface};
use Crehler\PaymentBundle\Application\Service\OrderTransactionSalesChannelResolver;
use Crehler\PaymentBundle\Domain\ValueObjects\RefundResult;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\PayNow\Handler\{BankHandler, BlikHandler, CardHandler};
use Crehler\PayNow\Infrastructure\Client\{PayNowExceptionDescriber, PayNowFactory};
use Paynow\Model\Refund\Status;
use Shopware\Core\Framework\Context;
use Throwable;

use function array_key_exists;
use function in_array;
use function sprintf;

/**
 * PayNow implementation of the bundle's refund port. Translates a gateway-agnostic
 * RefundCommand into a PayNow refund SDK call and maps the response to a RefundResult.
 * All native-entity / state-machine handling lives in the bundle handler — this only
 * talks to the gateway.
 *
 * Also exposes PayNow's predefined refund reasons (the API accepts a fixed enum, not free
 * text) so the admin shows a selector and we send a valid code instead of arbitrary text.
 */
final class PayNowRefundProvider implements RefundProviderPort, RefundReasonProviderInterface
{
    /**
     * @var mixed[]
     */
    private const SUPPORTED_HANDLERS = [
        BankHandler::class,
        BlikHandler::class,
        CardHandler::class,
    ];

    /**
     * PayNow's accepted refund reason enum → human label (primary locale pl-PL).
     * See https://docs.paynow.pl/docs/reference/v1/send-refund-request (RefundReason).
     *
     * @var mixed[]
     */
    private const REASONS = [
        'RMA' => 'Reklamacja (RMA)',
        'REFUND_BEFORE_14' => 'Odstąpienie od umowy — przed upływem 14 dni',
        'REFUND_AFTER_14' => 'Zwrot po upływie 14 dni',
        'OTHER' => 'Inny powód',
    ];

    public function __construct(
        private readonly PayNowFactory $payNowFactory,
        private readonly OrderTransactionSalesChannelResolver $salesChannelResolver,
        private readonly PayNowExceptionDescriber $exceptionDescriber,
        private readonly EnhancedLogger $logger,
    ) {
    }

    public function supports(string $handlerIdentifier): bool
    {
        return in_array($handlerIdentifier, self::SUPPORTED_HANDLERS, true);
    }

    public function getRefundReasons(): array
    {
        $reasons = [];
        foreach (self::REASONS as $code => $label) {
            $reasons[] = new RefundReason($code, $label);
        }

        return $reasons;
    }

    public function isReasonRequired(): bool
    {
        return true;
    }

    public function refund(RefundCommand $command, Context $context): RefundResult
    {
        $salesChannelId = $this->salesChannelResolver->resolve($command->orderTransactionId, $context);
        if ($salesChannelId === null) {
            // Without the sales channel the SDK client would fall back to default/global
            // credentials (wrong environment in a sandbox/production split). Fail closed.
            $this->logger->error('PayNow refund sales channel could not be resolved', [
                'orderTransactionId' => $command->orderTransactionId,
                'gatewayPaymentId' => $command->gatewayPaymentId,
            ]);

            return RefundResult::failed('Unable to resolve sales channel for PayNow refund');
        }

        // The refund entity id is unique per refund and stable across retries, so it is the
        // natural idempotency key — distinct from the payment's key (which would make PayNow
        // dedup the refund as the original payment). Without it, two equal partial refunds
        // would collide on a paymentId+amount hash, so refuse rather than risk a dedup.
        if ($command->refundId === null) {
            $this->logger->error('PayNow refund id missing; cannot build a unique idempotency key', [
                'orderTransactionId' => $command->orderTransactionId,
                'gatewayPaymentId' => $command->gatewayPaymentId,
                'amount' => $command->amount,
            ]);

            return RefundResult::failed('Missing refund id for PayNow refund idempotency key');
        }

        $idempotencyKey = 'refund_' . $command->refundId;

        // PayNow's reason is a fixed enum, not free text — send the operator-selected code
        // only when it is one PayNow recognises, otherwise omit it (the free-text note still
        // lives on the Shopware refund entity). This is why the admin shows a reason selector.
        $reason = $command->reasonCode !== null && array_key_exists($command->reasonCode, self::REASONS)
            ? $command->reasonCode
            : null;

        try {
            // PayNow expects the amount in minor units (grosze) — RefundCommand::$amount
            // already carries minor units, so it is passed through unchanged.
            $status = $this->payNowFactory
                ->refund($salesChannelId)
                ->create(
                    $command->gatewayPaymentId,
                    $idempotencyKey,
                    $command->amount,
                    $reason,
                );
        } catch (Throwable $e) {
            $detail = $this->exceptionDescriber->describe($e);

            $this->logger->error('PayNow refund API call failed', [
                'orderTransactionId' => $command->orderTransactionId,
                'gatewayPaymentId' => $command->gatewayPaymentId,
                'amount' => $command->amount,
                'exception' => $e->getMessage(),
                'detail' => $detail,
            ]);

            return RefundResult::failed($detail);
        }

        $gatewayRefundId = $status->getRefundId();
        $payNowStatus = $status->getStatus();

        $this->logger->info('PayNow refund response', [
            'orderTransactionId' => $command->orderTransactionId,
            'gatewayPaymentId' => $command->gatewayPaymentId,
            'gatewayRefundId' => $gatewayRefundId,
            'status' => $payNowStatus,
        ]);

        return match ($payNowStatus) {
            Status::SUCCESSFUL => RefundResult::completed($gatewayRefundId),
            Status::PENDING, Status::NEW => RefundResult::inProgress($gatewayRefundId),
            default => RefundResult::failed(sprintf('PayNow refund rejected with status "%s"', $payNowStatus)),
        };
    }
}
