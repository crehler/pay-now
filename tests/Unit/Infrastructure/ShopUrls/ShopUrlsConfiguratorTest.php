<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Tests\Unit\Infrastructure\ShopUrls;

use Crehler\PayNowPayment\Dto\ShopUrls\{ShopUrls, ShopUrlsResult};
use Crehler\PayNowPayment\Infrastructure\Client\PayNowExceptionDescriber;
use Crehler\PayNowPayment\Infrastructure\ShopUrls\{PayNowShopUrlsGateway, ShopUrlsConfigurator, ShopUrlsResolver};
use Doctrine\DBAL\Connection;
use Paynow\Environment;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use function array_map;

/**
 * The plugin writes its webhook address into PayNow for every environment that has keys —
 * otherwise a shop updated from 4.x keeps /paynowpayment/notification in the panel and no
 * order changes status (WT-1031).
 */
final class ShopUrlsConfiguratorTest extends TestCase
{
    /**
     * @var list<array{apiKey: string, environment: string, notificationUrl: string}>
     */
    private array $calls = [];

    public function testEveryEnvironmentWithKeysGetsTheAddresses(): void
    {
        $report = $this->configurator([
            'CrehlerPayNowPayment.config.apiKey' => 'live-key',
            'CrehlerPayNowPayment.config.signatureKeyLabel' => 'live-signature',
            'CrehlerPayNowPayment.config.sandboxApiKey' => 'sandbox-key',
            'CrehlerPayNowPayment.config.sandboxSignatureKeyLabel' => 'sandbox-signature',
        ])->configure();

        self::assertSame([
            ['apiKey' => 'live-key', 'environment' => Environment::PRODUCTION, 'notificationUrl' => 'https://shop.example/pl/payment/notification'],
            ['apiKey' => 'sandbox-key', 'environment' => Environment::SANDBOX, 'notificationUrl' => 'https://shop.example/pl/payment/notification'],
        ], $this->calls);
        self::assertSame([ShopUrlsResult::STATUS_OK, ShopUrlsResult::STATUS_OK], $this->statuses($report->results));
        self::assertFalse($report->hasFailure());
    }

    public function testAnEnvironmentWithoutKeysIsSkipped(): void
    {
        $report = $this->configurator([
            'CrehlerPayNowPayment.config.sandboxApiKey' => 'sandbox-key',
            'CrehlerPayNowPayment.config.sandboxSignatureKeyLabel' => 'sandbox-signature',
        ])->configure();

        self::assertCount(1, $this->calls);
        self::assertSame([ShopUrlsResult::STATUS_SKIPPED, ShopUrlsResult::STATUS_OK], $this->statuses($report->results));
    }

    public function testUnsavedFormKeysWinOverTheSavedOnes(): void
    {
        $this->configurator([
            'CrehlerPayNowPayment.config.sandboxApiKey' => 'saved-key',
            'CrehlerPayNowPayment.config.sandboxSignatureKeyLabel' => 'saved-signature',
        ])->configure(null, ['sandboxApiKey' => 'typed-key']);

        self::assertSame('typed-key', $this->calls[0]['apiKey']);
    }

    public function testAGatewayErrorIsReportedNotThrown(): void
    {
        $report = $this->configurator(
            [
                'CrehlerPayNowPayment.config.apiKey' => 'live-key',
                'CrehlerPayNowPayment.config.signatureKeyLabel' => 'live-signature',
            ],
            failing: true,
        )->configure();

        self::assertSame([ShopUrlsResult::STATUS_FAILED, ShopUrlsResult::STATUS_SKIPPED], $this->statuses($report->results));
        self::assertTrue($report->hasFailure());
    }

    public function testWithoutAStorefrontDomainNothingIsSent(): void
    {
        $report = $this->configurator(
            [
                'CrehlerPayNowPayment.config.apiKey' => 'live-key',
                'CrehlerPayNowPayment.config.signatureKeyLabel' => 'live-signature',
            ],
            domains: [],
        )->configure();

        self::assertSame([], $this->calls);
        self::assertNull($report->urls);
        self::assertSame(ShopUrlsResult::STATUS_FAILED, $report->results[0]->status);
    }

    /**
     * @param array<string, string>                                                           $savedConfig
     * @param list<array{url: string, language_id: string, channel_language_id: string}>|null $domains
     */
    private function configurator(array $savedConfig, bool $failing = false, ?array $domains = null): ShopUrlsConfigurator
    {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('getString')->willReturnCallback(
            static fn (string $key): string => $savedConfig[$key] ?? '',
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($domains ?? [
            ['url' => 'https://shop.example/pl', 'language_id' => 'a', 'channel_language_id' => 'a'],
        ]);

        $gateway = $this->createStub(PayNowShopUrlsGateway::class);
        $gateway->method('changeUrls')->willReturnCallback(
            function (string $apiKey, string $signatureKey, string $environment, ShopUrls $urls) use ($failing): void {
                if ($failing) {
                    throw new RuntimeException('Unauthorized');
                }

                $this->calls[] = ['apiKey' => $apiKey, 'environment' => $environment, 'notificationUrl' => $urls->notificationUrl];
            },
        );

        return new ShopUrlsConfigurator(
            $systemConfig,
            new ShopUrlsResolver($connection),
            $gateway,
            new PayNowExceptionDescriber(),
            new NullLogger(),
        );
    }

    /**
     * @param list<ShopUrlsResult> $results
     *
     * @return list<string>
     */
    private function statuses(array $results): array
    {
        return array_map(static fn (ShopUrlsResult $result): string => $result->status, $results);
    }
}
