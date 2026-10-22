<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Services;

use Crehler\PaymentBundle\Infrastructure\Port\ConsentProvider;
use Crehler\PaymentBundle\Infrastructure\Struct\ConsentStruct;
use Crehler\PayNowPayment\Constant\Methods;
use Crehler\PayNowPayment\Infrastructure\Client\PayNowFactory;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Cache\{CacheInterface, ItemInterface};
use Throwable;

use function array_map;
use function array_slice;
use function array_values;
use function hash;
use function in_array;
use function sprintf;

/**
 * PayNow's GDPR data-processing notices (GET /v3/payments/dataprocessing/notices).
 *
 * White Label integrations must fetch and SHOW them — they are mBank's information
 * obligation under GDPR art. 13, not a consent to collect. They used to be rendered as a
 * required checkbox with the notice body as its label, which also dropped the title (the
 * opening sentence naming the controller), so the text started mid-thought (WT-1030).
 * Now they are an informational disclosure, as PayNow's own plugins present them: the
 * first notice's title in one line, every notice in full behind "Read more".
 *
 * The response is cached for 24 hours per sales channel, locale and environment, like PayNow's
 * PrestaShop plugin does — the notices change rarely and the checkout confirm page
 * should not wait for the gateway on every visit. A failed call is not cached.
 */
final readonly class PayNowConsentProvider implements ConsentProvider
{
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private PayNowFactory $payNowFactory,
        private EntityRepository $languageRepository,
        private LoggerInterface $logger,
        private CacheInterface $cache,
    ) {
    }

    public function supportsPaymentMethod(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return in_array($paymentMethodEntity->getTechnicalName(), [
            Methods::BLIK_NAME,
            Methods::BANK_NAME,
            Methods::CARD_NAME,
            Methods::PAYPO_NAME,
        ], true);
    }

    public function getConsent(PaymentMethodEntity $paymentMethodEntity, SalesChannelContext $context): ?ConsentStruct
    {
        try {
            $localeCode = $this->getLanguage($context)?->getLocale()?->getCode() ?? 'pl-PL';
            $notices = $this->notices($context->getSalesChannelId(), $localeCode);
        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch PayNow consent', [
                'exception' => $e,
                'paymentMethod' => $paymentMethodEntity->getTechnicalName(),
            ]);

            return null;
        }

        return self::toConsent($notices);
    }

    /**
     * @param list<array{title: string, content: string, locale: string}> $notices
     */
    public static function toConsent(array $notices): ?ConsentStruct
    {
        $first = $notices[0] ?? null;

        if ($first === null) {
            return null;
        }

        // The first notice's title is the line on the page; the modal repeats it above the
        // body, so only the notices after it carry their own heading inside the content.
        $content = $first['content'];
        foreach (array_slice($notices, 1) as $notice) {
            $content .= sprintf('<p><strong>%s</strong></p>%s', $notice['title'], $notice['content']);
        }

        return new ConsentStruct(
            content: $content,
            locale: $first['locale'],
            title: $first['title'] !== '' ? $first['title'] : null,
            requiresAcceptance: false,
        );
    }

    /**
     * Sandbox and production are separate PayNow accounts with their own notices; a switch
     * of the sandbox flag must not serve the other environment's text for up to 24 hours.
     */
    public static function cacheKey(string $salesChannelId, string $localeCode, bool $sandbox): string
    {
        return 'crehler_paynow_notices_' . hash('xxh128', $salesChannelId . '|' . $localeCode . '|' . ($sandbox ? 'sandbox' : 'production'));
    }

    /**
     * @return list<array{title: string, content: string, locale: string}>
     */
    private function notices(string $salesChannelId, string $localeCode): array
    {
        $key = self::cacheKey($salesChannelId, $localeCode, $this->payNowFactory->isSandboxMode($salesChannelId));

        return $this->cache->get($key, function (ItemInterface $item) use ($salesChannelId, $localeCode): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            // The SDK documents an array but leaves the list null when the gateway sends
            // no notices — `?:` covers that without trusting the docblock.
            $notices = $this->payNowFactory->dataProcessing($salesChannelId)->getNotices($localeCode)->getAll() ?: [];

            return array_values(array_map(static fn ($notice): array => [
                'title' => (string) $notice->getTitle(),
                'content' => (string) $notice->getContent(),
                'locale' => (string) $notice->getLocale(),
            ], $notices));
        });
    }

    private function getLanguage(SalesChannelContext $context): ?LanguageEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $context->getLanguageId()));
        $criteria->addAssociation('locale');

        return $this->languageRepository->search($criteria, $context->getContext())->first();
    }
}
