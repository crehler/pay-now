<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Services;

use Crehler\PaymentBundle\Infrastructure\Port\ConsentProvider;
use Crehler\PaymentBundle\Infrastructure\Struct\ConsentStruct;
use Crehler\PayNow\Constant\Methods;
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Throwable;

use function in_array;

/**
 * PayNow implementation of ConsentProvider.
 * Provides legal clauses/consent from PayNow API.
 */
final readonly class PayNowConsentProvider implements ConsentProvider
{
    public function __construct(
        private PayNowFactory $payNowFactory,
        private EntityRepository $languageRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function supportsPaymentMethod(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return in_array($paymentMethodEntity->getTechnicalName(), [
            Methods::BLIK_NAME,
            Methods::BANK_NAME,
            Methods::CARD_NAME,
        ], true);
    }

    public function getConsent(PaymentMethodEntity $paymentMethodEntity, SalesChannelContext $context): ?ConsentStruct
    {
        try {
            $language = $this->getLanguage($context);
            $localeCode = $language?->getLocale()?->getCode() ?? 'pl-PL';

            $data = $this->payNowFactory->dataProcessing($context->getSalesChannelId())->getNotices($localeCode);
            $clauses = $data->getAll()[0] ?? null;

            if ($clauses === null) {
                return null;
            }

            // PayNow's data-processing notices are an information obligation (RODO),
            // not a consent to tick — surface them as an informational disclosure.
            return new ConsentStruct(
                content: $clauses->getContent(),
                locale: $clauses->getLocale(),
                title: $clauses->getTitle(),
                requiresAcceptance: false,
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch PayNow consent', [
                'exception' => $e,
                'paymentMethod' => $paymentMethodEntity->getTechnicalName(),
            ]);

            return null;
        }
    }

    private function getLanguage(SalesChannelContext $context): ?LanguageEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $context->getLanguageId()));
        $criteria->addAssociation('locale');

        return $this->languageRepository->search($criteria, $context->getContext())->first();
    }
}
