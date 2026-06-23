<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Services;

use Crehler\PaymentBundle\Infrastructure\Provider\{AbstractPaymentSubMethodProvider, RawSubMethod};
use Crehler\PayNow\Handler\BankHandler;
use Crehler\PayNow\Infrastructure\Client\PayNowFactory;
use Paynow\Exception\PaynowException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Throwable;

use function in_array;

/**
 * Provides PayNow PBL bank channels as sub-methods for the PayNow Bank handler.
 * Filtering/mapping (min/max + PaymentSubMethod) is handled by the bundle base;
 * this only fetches the PayNow PBL methods.
 */
final class PayNowPaymentSubMethodsService extends AbstractPaymentSubMethodProvider
{
    /**
     * @var mixed[]
     */
    private const SUPPORTED_HANDLERS = [
        BankHandler::class,
    ];

    public function __construct(
        private PayNowFactory $payNowFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function supportsPaymentMethod(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return in_array($paymentMethodEntity->getHandlerIdentifier(), self::SUPPORTED_HANDLERS, true);
    }

    protected function fetchRawSubMethods(
        PaymentMethodEntity $paymentMethodEntity,
        int $paymentValue,
        SalesChannelContext $context,
    ): iterable {
        try {
            $payNow = $this->payNowFactory->payment($context->getSalesChannelId());
        } catch (Throwable $e) {
            $this->logger->error('PayNow payment method is not available', ['exception' => $e]);

            return [];
        }

        try {
            $methods = $payNow->getPaymentMethods(
                currency: $context->getCurrency()->getIsoCode(),
                amount: $paymentValue,
                applePayEnabled: true
            );
        } catch (PaynowException $e) {
            $this->logger->error('PayNow payment exception: ' . $e->getMessage(), ['exception' => $e]);

            return [];
        }

        $raw = [];

        foreach ($methods->getOnlyPbls() as $method) {
            $raw[] = new RawSubMethod(
                providerId: (string) $method->getId(),
                name: $method->getName(),
                mediaUrl: $method->getImage(),
            );
        }

        return $raw;
    }
}
