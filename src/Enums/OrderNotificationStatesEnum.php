<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Enums;

enum OrderNotificationStatesEnum: string
{
    case STATE_ACCEPTED = 'accepted';
    case STATE_MISSING_ORDER = 'missing_order';
    case STATE_MISSING_AUTH = 'missing_auth';
    case STATE_NO_UPSERT = 'no_upsert';
    case STATE_EXCEPTION = 'exception';
}
