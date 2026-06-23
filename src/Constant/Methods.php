<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Constant;

use Paynow\Model\PaymentMethods\Type;

final class Methods
{
    public const NAME = 'paynow';
    public const BANK_ID = '01905943035d7108a28d946458c82282';
    public const CARD_ID = '019059433b7770e484374064ad9ef31e';
    public const BLIK_ID = '019059436d3d730a93bbdbb689ec5269';

    public const BANK_NAME = 'paynow_bank';
    public const CARD_NAME = 'paynow_card';
    public const BLIK_NAME = 'paynow_blik';

    public const PAYNOW_MAP = [
        Type::BLIK => self::BLIK_ID,
        Type::CARD => self::CARD_ID,
        Type::PBL => self::BANK_ID,
    ];
}
