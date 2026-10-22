<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Infrastructure\ShopUrls;

use Crehler\PayNowPayment\Dto\ShopUrls\{ShopUrls, ShopUrlsReport, ShopUrlsResult};
use Crehler\PayNowPayment\Infrastructure\Client\PayNowExceptionDescriber;
use Paynow\Environment;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

use function is_scalar;
use function trim;

/**
 * Puts the plugin's notification and return address into PayNow — for every environment
 * that has credentials, so the sandbox point and the production point are both right.
 *
 * Runs after the plugin configuration is saved (ShopUrlsSubscriber) and on demand from
 * the "PayNow panel addresses" card. Without it the merchant had to copy the webhook into
 * the PayNow panel by hand, and a shop updated from 4.x kept the old
 * /paynowpayment/notification there — every notification answered 404 and no order ever
 * changed status.
 */
final readonly class ShopUrlsConfigurator
{
    public const ENVIRONMENT_LIVE = 'live';

    public const ENVIRONMENT_SANDBOX = 'sandbox';

    private const CONFIG_DOMAIN = 'CrehlerPayNowPayment.config.';

    /**
     * environment => [api key field, signature key field, Paynow\Environment value]
     */
    private const ENVIRONMENTS = [
        self::ENVIRONMENT_LIVE => ['apiKey', 'signatureKeyLabel', Environment::PRODUCTION],
        self::ENVIRONMENT_SANDBOX => ['sandboxApiKey', 'sandboxSignatureKeyLabel', Environment::SANDBOX],
    ];

    public function __construct(
        private SystemConfigService $systemConfigService,
        private ShopUrlsResolver $resolver,
        private PayNowShopUrlsGateway $gateway,
        private PayNowExceptionDescriber $exceptionDescriber,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $postedConfig unsaved form values (keys without the domain
     *                                           prefix); a non-empty value wins over the saved one
     */
    public function configure(?string $salesChannelId = null, array $postedConfig = []): ShopUrlsReport
    {
        $urls = $this->resolver->resolve($salesChannelId);

        $results = [];
        foreach (self::ENVIRONMENTS as $environment => [$apiKeyField, $signatureKeyField, $paynowEnvironment]) {
            $results[] = $this->configureEnvironment(
                environment: $environment,
                paynowEnvironment: $paynowEnvironment,
                apiKey: $this->value($postedConfig, $apiKeyField, $salesChannelId),
                signatureKey: $this->value($postedConfig, $signatureKeyField, $salesChannelId),
                urls: $urls,
                salesChannelId: $salesChannelId,
            );
        }

        return new ShopUrlsReport($urls, $results);
    }

    private function configureEnvironment(
        string $environment,
        string $paynowEnvironment,
        string $apiKey,
        string $signatureKey,
        ?ShopUrls $urls,
        ?string $salesChannelId,
    ): ShopUrlsResult {
        if ($apiKey === '' || $signatureKey === '') {
            return new ShopUrlsResult($environment, ShopUrlsResult::STATUS_SKIPPED);
        }

        if ($urls === null) {
            return new ShopUrlsResult($environment, ShopUrlsResult::STATUS_FAILED, 'No storefront sales channel domain to build the addresses from.');
        }

        try {
            $this->gateway->changeUrls($apiKey, $signatureKey, $paynowEnvironment, $urls);
        } catch (Throwable $e) {
            $message = $this->exceptionDescriber->describe($e);

            $this->logger->warning('Could not set the PayNow shop URLs', [
                'environment' => $environment,
                'salesChannelId' => $salesChannelId,
                'notificationUrl' => $urls->notificationUrl,
                'reason' => $message,
            ]);

            return new ShopUrlsResult($environment, ShopUrlsResult::STATUS_FAILED, $message);
        }

        return new ShopUrlsResult($environment, ShopUrlsResult::STATUS_OK);
    }

    /**
     * @param array<string, mixed> $postedConfig
     */
    private function value(array $postedConfig, string $field, ?string $salesChannelId): string
    {
        $posted = $postedConfig[$field] ?? null;

        if (is_scalar($posted) && trim((string) $posted) !== '') {
            return trim((string) $posted);
        }

        return trim($this->systemConfigService->getString(self::CONFIG_DOMAIN . $field, $salesChannelId));
    }
}
