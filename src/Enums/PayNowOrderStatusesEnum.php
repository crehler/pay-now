<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Enums;

enum PayNowOrderStatusesEnum: string
{
    case CONFIRMED = 'CONFIRMED';
    case REJECTED = 'REJECTED';
    case CANCEL = 'CANCEL';
    case ERROR = 'ERROR';
}
