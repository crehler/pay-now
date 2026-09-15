<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Enums;

/**
 * PayNow's payment statuses, mirroring Paynow\Model\Payment\Status in the SDK.
 *
 * The whole vocabulary is listed, not just the terminal half, because "a status we know
 * means the payment is still running" and "a status we have never heard of" are different
 * answers and only one of them is worth a warning. Leaving NEW and PENDING out made every
 * freshly created payment look unmapped.
 *
 * CANCEL and CANCELLED both exist on purpose. The SDK spells it CANCELLED; this enum has
 * always spelled it CANCEL, and nothing mapped the SDK's spelling — so a customer who
 * cancelled at PayNow produced a status that matched no case, the notification subscriber
 * fell through to NONE, and the order transaction stayed open forever. Keeping both costs
 * one case and cannot break whichever spelling the gateway actually sends.
 */
enum PayNowOrderStatusesEnum: string
{
    case NEW = 'NEW';
    case PENDING = 'PENDING';
    case WAITING_FOR_CONFIRMATION = 'WAITING_FOR_CONFIRMATION';
    case CONFIRMED = 'CONFIRMED';
    case REJECTED = 'REJECTED';
    case CANCEL = 'CANCEL';
    case CANCELLED = 'CANCELLED';
    case ERROR = 'ERROR';
    case EXPIRED = 'EXPIRED';
    case ABANDONED = 'ABANDONED';

    /**
     * The payment is over and it did not succeed. Nothing further will happen to it, so a
     * handler that sees this must fail the attempt rather than wait for a notification.
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::REJECTED,
            self::CANCEL,
            self::CANCELLED,
            self::ERROR,
            self::EXPIRED,
            self::ABANDONED => true,
            default => false,
        };
    }

    /**
     * The payment is still running. The gateway notification decides the outcome, so the
     * handler leaves the transaction in progress.
     */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::NEW,
            self::PENDING,
            self::WAITING_FOR_CONFIRMATION => true,
            default => false,
        };
    }
}
