<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Storefront\Controller;

use Crehler\PaymentBundle\Infrastructure\Api\NotificationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The webhook address of the legacy 4.x line, /paynowpayment/notification.
 *
 * Shops updated from 4.x (same technical name, so it reaches them as an ordinary update)
 * still have this address in the PayNow panel. 6.x receives notifications on the bundle's
 * /payment/notification, so every notification sent here answered 404 and no order changed
 * status — until somebody noticed and edited the panel. The plugin now also writes the new
 * address into PayNow itself (ShopUrlsConfigurator), but that needs a configuration save or
 * may fail; this alias keeps notifications flowing in the meantime.
 *
 * Forwards to the bundle's notification controller, so both addresses share one pipeline:
 * same signature verification, same status mapping, same response. Named frontend.* so the
 * storefront router keeps the sales channel's path prefix (/pl/paynowpayment/notification
 * works as well, WT-1029).
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
#[Autoconfigure(public: true)]
final class LegacyNotificationController extends AbstractController
{
    #[Route(
        path: 'paynowpayment/notification',
        name: 'frontend.cr.paynow.legacy-notification',
        defaults: ['auth_required' => false],
        methods: ['POST'],
    )]
    public function handle(): Response
    {
        return $this->forward(NotificationController::class . '::handleNotification');
    }
}
