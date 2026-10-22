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
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

use function parse_url;
use function rtrim;
use function trim;

use const PHP_URL_PATH;

/**
 * Builds the PayNow notification and return addresses from a storefront sales channel
 * domain.
 *
 * The domain's PATH is part of the address. A shop whose domains are https://shop/pl and
 * https://shop/de has no sales channel at the bare host, so https://shop/payment/notification
 * matches nothing there; https://shop/pl/payment/notification does (WT-1029). Any storefront
 * domain of the shop works — the webhook finds the transaction by the gateway id, not by the
 * channel it arrives on — so the choice only has to be stable and sensible:
 *   1. a domain without a path (the plainest URL),
 *   2. otherwise the domain in the sales channel's default language (/pl for a Polish shop),
 *   3. otherwise the first one alphabetically.
 * Active sales channels win over inactive ones.
 */
final readonly class ShopUrlsResolver
{
    public const NOTIFICATION_PATH = '/payment/notification';

    public function __construct(private Connection $connection)
    {
    }

    public function resolve(?string $salesChannelId = null): ?ShopUrls
    {
        $query = 'SELECT d.url, LOWER(HEX(d.language_id)) AS language_id, LOWER(HEX(sc.language_id)) AS channel_language_id
                    FROM sales_channel_domain d
                    INNER JOIN sales_channel sc ON sc.id = d.sales_channel_id
                   WHERE sc.type_id = :storefront';
        $parameters = ['storefront' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)];

        if ($salesChannelId !== null) {
            $query .= ' AND sc.id = :salesChannelId';
            $parameters['salesChannelId'] = Uuid::fromHexToBytes($salesChannelId);
        }

        $query .= ' ORDER BY sc.active DESC, d.url ASC';

        /** @var list<array{url: string, language_id: string, channel_language_id: string}> $domains */
        $domains = $this->connection->fetchAllAssociative($query, $parameters);

        $domainUrl = self::pickDomain($domains);

        return $domainUrl === null ? null : self::fromDomain($domainUrl);
    }

    /**
     * @param list<array{url: string, language_id: string, channel_language_id: string}> $domains in preference order
     */
    public static function pickDomain(array $domains): ?string
    {
        foreach ($domains as $domain) {
            if (trim((string) parse_url($domain['url'], PHP_URL_PATH), '/') === '') {
                return $domain['url'];
            }
        }

        foreach ($domains as $domain) {
            if ($domain['language_id'] === $domain['channel_language_id']) {
                return $domain['url'];
            }
        }

        return $domains[0]['url'] ?? null;
    }

    public static function fromDomain(string $domainUrl): ShopUrls
    {
        $base = rtrim($domainUrl, '/');

        return new ShopUrls(
            domainUrl: $base,
            notificationUrl: $base . self::NOTIFICATION_PATH,
            returnUrl: $base,
        );
    }
}
