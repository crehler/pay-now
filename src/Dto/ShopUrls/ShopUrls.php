<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Dto\ShopUrls;

/**
 * The two addresses a PayNow payment point needs, built from one storefront domain —
 * path included, so a shop whose domains are https://shop/pl, https://shop/de gets
 * https://shop/pl/payment/notification rather than a bare-host URL that matches no
 * sales channel.
 */
final readonly class ShopUrls
{
    public function __construct(
        public string $domainUrl,
        public string $notificationUrl,
        public string $returnUrl,
    ) {
    }

    /**
     * @return array{domainUrl: string, notificationUrl: string, returnUrl: string}
     */
    public function toArray(): array
    {
        return [
            'domainUrl' => $this->domainUrl,
            'notificationUrl' => $this->notificationUrl,
            'returnUrl' => $this->returnUrl,
        ];
    }
}
