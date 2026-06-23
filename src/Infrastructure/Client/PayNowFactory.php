<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Infrastructure\Client;

use Crehler\PaymentBundle\Infrastructure\Client\AbstractGatewayClientFactory;
use Paynow\{Client, Environment, Notification};
use Paynow\Exception\{ConfigurationException, SignatureVerificationException};
use Paynow\Service\{DataProcessing, Payment, Refund, ShopConfiguration};

final class PayNowFactory extends AbstractGatewayClientFactory
{
    /**
     * @var string
     */
    private const API_KEY = 'CrehlerPayNow.config.apiKey';
    /**
     * @var string
     */
    private const SIGNATURE_KEY_LABEL = 'CrehlerPayNow.config.signatureKeyLabel';
    /**
     * @var string
     */
    private const SANDBOX_API_KEY = 'CrehlerPayNow.config.sandboxApiKey';
    /**
     * @var string
     */
    private const SANDBOX_SIGNATURE_KEY_LABEL = 'CrehlerPayNow.config.sandboxSignatureKeyLabel';
    /**
     * @var string
     */
    private const ENABLE_SANDBOX = 'CrehlerPayNow.config.enableSandbox';

    /**
     * @throws ConfigurationException
     */
    public function client(?string $salesChannelId = null): Client
    {
        $sandbox = $this->isSandbox(self::ENABLE_SANDBOX, $salesChannelId);

        $apiKey = $this->requireString(
            $this->selectKey($sandbox, self::API_KEY, self::SANDBOX_API_KEY),
            $salesChannelId,
        );
        $signatureKeyLabel = $this->requireString(
            $this->selectKey($sandbox, self::SIGNATURE_KEY_LABEL, self::SANDBOX_SIGNATURE_KEY_LABEL),
            $salesChannelId,
        );

        return new Client($apiKey, $signatureKeyLabel, $sandbox ? Environment::SANDBOX : Environment::PRODUCTION);
    }

    /**
     * @throws SignatureVerificationException
     */
    public function notification(?string $salesChannelId = null, ?string $payload = null, ?array $headers = null): Notification
    {
        $sandbox = $this->isSandbox(self::ENABLE_SANDBOX, $salesChannelId);
        // Fail closed: a missing/empty signature key would otherwise verify webhooks
        // against an empty secret. requireString() throws when the key is unset, so an
        // unsigned/forged notification is rejected rather than silently trusted.
        $signatureKeyLabel = $this->requireString(
            $this->selectKey($sandbox, self::SIGNATURE_KEY_LABEL, self::SANDBOX_SIGNATURE_KEY_LABEL),
            $salesChannelId,
        );

        return new Notification($signatureKeyLabel, $payload, $headers);
    }

    /**
     * @throws ConfigurationException
     */
    public function payment(?string $salesChannelId = null): Payment
    {
        return new Payment($this->client($salesChannelId));
    }

    /**
     * @throws ConfigurationException
     */
    public function dataProcessing(?string $salesChannelId = null): DataProcessing
    {
        return new DataProcessing($this->client($salesChannelId));
    }

    /**
     * @throws ConfigurationException
     */
    public function refund(?string $salesChannelId = null): Refund
    {
        return new Refund($this->client($salesChannelId));
    }

    /**
     * @throws ConfigurationException
     */
    public function shopConfiguration(?string $salesChannelId = null): ShopConfiguration
    {
        return new ShopConfiguration($this->client($salesChannelId));
    }
}
