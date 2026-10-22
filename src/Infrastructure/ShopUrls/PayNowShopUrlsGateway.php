<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Infrastructure\ShopUrls;

use Crehler\PayNowPayment\Dto\ShopUrls\ShopUrls;
use Paynow\Client;
use Paynow\Service\ShopConfiguration;

use function md5;

/**
 * PATCH /v3/configuration/shop/urls — sets the payment point's notification and return
 * address. PayNow has no read counterpart (GET answers 404), and the payment request has
 * no notification-URL field, so this is the only way for the plugin to put its webhook
 * address into PayNow. PayNow's own plugins (PrestaShop, WooCommerce, …) call it on every
 * configuration save.
 *
 * Not final: the configurator's tests replace it with a stub — the real one talks to PayNow.
 */
class PayNowShopUrlsGateway
{
    /**
     * @param 'production'|'sandbox' $environment Paynow\Environment value
     */
    public function changeUrls(string $apiKey, string $signatureKey, string $environment, ShopUrls $urls): void
    {
        // The SDK's default idempotency key is md5(apiKey) alone. PayNow replays the stored
        // response for a repeated key, so after a domain change the new addresses would
        // never be applied. Keying on the addresses too keeps a repeat of the same request
        // idempotent and lets a different one through.
        $idempotencyKey = md5($apiKey . '|' . $urls->returnUrl . '|' . $urls->notificationUrl);

        (new ShopConfiguration(new Client($apiKey, $signatureKey, $environment)))
            ->changeUrls($urls->returnUrl, $urls->notificationUrl, $idempotencyKey);
    }
}
