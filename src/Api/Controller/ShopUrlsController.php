<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Api\Controller;

use Crehler\PayNowPayment\Infrastructure\ShopUrls\{ShopUrlsConfigurator, ShopUrlsResolver};
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function is_string;
use function json_decode;

/**
 * Backs the "PayNow panel addresses" card in the plugin configuration.
 *
 * GET  — the addresses for the selected scope, computed by the same resolver the
 *        configuration save uses, so the card cannot show something else than what is sent.
 * POST — put them into PayNow now. Takes the UNSAVED credentials from the form, like the
 *        connection test, so it works before the operator hits Save.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
#[Autoconfigure(public: true)]
final readonly class ShopUrlsController
{
    public function __construct(
        private ShopUrlsResolver $resolver,
        private ShopUrlsConfigurator $configurator,
    ) {
    }

    #[Route(
        path: '/api/_action/crehler-paynow/shop-urls',
        name: 'api.action.crehler-paynow.shop-urls.read',
        defaults: ['_acl' => ['system_config:read']],
        methods: ['GET'],
    )]
    public function read(Request $request): JsonResponse
    {
        $salesChannelId = self::salesChannelId($request->query->get('salesChannelId'));

        return new JsonResponse(['urls' => $this->resolver->resolve($salesChannelId)?->toArray()]);
    }

    #[Route(
        path: '/api/_action/crehler-paynow/shop-urls',
        name: 'api.action.crehler-paynow.shop-urls.configure',
        defaults: ['_acl' => ['system_config:update']],
        methods: ['POST'],
    )]
    public function configure(Request $request): JsonResponse
    {
        // The admin httpClient posts JSON, so the body lives in the content.
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];

        $report = $this->configurator->configure(self::salesChannelId($payload['salesChannelId'] ?? null), $config);

        return new JsonResponse(
            $report->toArray(),
            $report->hasFailure() ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK,
        );
    }

    private static function salesChannelId(mixed $value): ?string
    {
        return is_string($value) && Uuid::isValid($value) ? $value : null;
    }
}
