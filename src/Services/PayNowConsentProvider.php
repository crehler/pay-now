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

            // Rendered as a required checkbox, matching Tpay and ING Pay: the customer
            // ticks the gateway's clauses before the order button will submit.
            //
            // Worth knowing what this changes. These come from PayNow's data-processing
            // endpoint and read as an information obligation under GDPR art. 13 — the kind
            // of notice a controller has to present rather than collect agreement to, which
            // is why they were shown as a passive disclosure until now. Ticking one is not
            // what makes the notice effective. If PayNow's clauses stay purely
            // informational, flipping this back to false is the whole change.
            return new ConsentStruct(
                content: $clauses->getContent(),
                locale: $clauses->getLocale(),
                title: $clauses->getTitle(),
                requiresAcceptance: true,
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
