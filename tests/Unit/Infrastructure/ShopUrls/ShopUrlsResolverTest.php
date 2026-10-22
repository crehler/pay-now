<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Tests\Unit\Infrastructure\ShopUrls;

use Crehler\PayNowPayment\Infrastructure\ShopUrls\ShopUrlsResolver;
use PHPUnit\Framework\TestCase;

/**
 * The PayNow addresses keep the sales channel domain's path: in a shop whose domains are
 * https://shop/pl and https://shop/de nothing answers at the bare host (WT-1029, WT-1031).
 */
final class ShopUrlsResolverTest extends TestCase
{
    private const PL = '0193a2b1c2d3e4f5a6b7c8d9e0f1a2b3';

    private const DE = '0193a2b1c2d3e4f5a6b7c8d9e0f1a2b4';

    public function testTheDomainPathIsKeptInBothAddresses(): void
    {
        $urls = ShopUrlsResolver::fromDomain('https://sklep.deante.pl/pl');

        self::assertSame('https://sklep.deante.pl/pl/payment/notification', $urls->notificationUrl);
        self::assertSame('https://sklep.deante.pl/pl', $urls->returnUrl);
    }

    public function testATrailingSlashDoesNotDoubleUp(): void
    {
        self::assertSame(
            'https://shop.example/de/payment/notification',
            ShopUrlsResolver::fromDomain('https://shop.example/de/')->notificationUrl,
        );
    }

    public function testADomainWithoutAPathIsPreferred(): void
    {
        self::assertSame('https://shop.example', ShopUrlsResolver::pickDomain([
            $this->domain('https://shop.example/de', self::DE, self::PL),
            $this->domain('https://shop.example', self::DE, self::PL),
        ]));
    }

    public function testWithOnlyPrefixedDomainsTheChannelsDefaultLanguageWins(): void
    {
        self::assertSame('https://shop.example/pl', ShopUrlsResolver::pickDomain([
            $this->domain('https://shop.example/de', self::DE, self::PL),
            $this->domain('https://shop.example/pl', self::PL, self::PL),
        ]));
    }

    public function testOtherwiseTheFirstDomainIsUsed(): void
    {
        self::assertSame('https://shop.example/de', ShopUrlsResolver::pickDomain([
            $this->domain('https://shop.example/de', self::DE, self::PL),
            $this->domain('https://shop.example/en', self::DE, self::PL),
        ]));
    }

    public function testNoDomainMeansNoAddresses(): void
    {
        self::assertNull(ShopUrlsResolver::pickDomain([]));
    }

    /**
     * @return array{url: string, language_id: string, channel_language_id: string}
     */
    private function domain(string $url, string $languageId, string $channelLanguageId): array
    {
        return ['url' => $url, 'language_id' => $languageId, 'channel_language_id' => $channelLanguageId];
    }
}
