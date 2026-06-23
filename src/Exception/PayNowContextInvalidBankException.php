<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class PayNowContextInvalidBankException extends ShopwareHttpException
{
    public function __construct()
    {
        parent::__construct('Incorrect bank selected.');
    }

    public function getErrorCode(): string
    {
        return 'CHECKOUT__INVALID_PAYNOW_BANK';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
