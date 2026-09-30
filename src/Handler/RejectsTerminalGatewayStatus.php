<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Handler;

use Crehler\PaymentBundle\Infrastructure\Handler\PaymentResult;
use Crehler\PayNowPayment\Enums\PayNowOrderStatusesEnum;

use function sprintf;
use function strtoupper;

/**
 * Turns the status PayNow answers an authorization with into a failed payment.
 *
 * Only BLIK ever looked at that status. Bank, Card and PayPo read the redirect URL and
 * nothing else, so a payment PayNow had already rejected came back as a success with an
 * empty redirect — and the shared flow reads an empty redirect as "accepted without a
 * redirect, the webhook will finish it". No webhook ever came, because the payment was
 * over, and the order transaction stayed in progress for good.
 *
 * Deliberately not "no redirect URL means failure": a card accepted without 3DS and a BLIK
 * authorized on a T6 code both legitimately have no URL to send the browser to. The status
 * is the fact that separates those from a rejection; the URL never was.
 *
 * An unknown status is left in flight rather than failed. The gateway can add one at any
 * time, and a plugin that starts failing payments on a word it does not recognise is worse
 * than one that waits for the notification.
 */
trait RejectsTerminalGatewayStatus
{
    private function failIfGatewayRejected(?string $gatewayStatus, string $methodLabel): ?PaymentResult
    {
        $status = PayNowOrderStatusesEnum::tryFrom(strtoupper((string) $gatewayStatus));

        if ($status === null) {
            $this->logger->warning('PayNow returned an unmapped status; treating the payment as in flight', [
                'method' => $methodLabel,
                'status' => $gatewayStatus,
            ]);

            return null;
        }

        if (!$status->isFailure()) {
            return null;
        }

        return PaymentResult::failure(
            sprintf('PayNow rejected the %s payment with status "%s"', $methodLabel, $status->value)
        );
    }
}
