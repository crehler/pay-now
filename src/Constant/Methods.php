<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Constant;

use Paynow\Model\PaymentMethods\Type;

final class Methods
{
    public const NAME = 'paynow';
    public const BANK_ID = '01905943035d7108a28d946458c82282';
    public const CARD_ID = '019059433b7770e484374064ad9ef31e';
    public const BLIK_ID = '019059436d3d730a93bbdbb689ec5269';
    public const PAYPO_ID = '738a8dfd12d92aac208e539da3812f9c';

    public const BANK_NAME = 'paynow_bank';
    public const CARD_NAME = 'paynow_card';
    public const BLIK_NAME = 'paynow_blik';
    public const PAYPO_NAME = 'paynow_paypo';

    /**
     * PayNow's fixed id for PayPo in White Label mode. Unlike the PBL bank ids — which
     * differ per bank, change, and must be read from /v3/payments/paymentmethods before
     * every payment — this one is a constant the documentation names outright.
     *
     * A string because that is what the payment request carries; PayNow compares it as a
     * number either way.
     */
    public const PAYPO_GATEWAY_ID = '3000';

    public const PAYNOW_MAP = [
        Type::BLIK => self::BLIK_ID,
        Type::CARD => self::CARD_ID,
        Type::PBL => self::BANK_ID,
    ];
}
