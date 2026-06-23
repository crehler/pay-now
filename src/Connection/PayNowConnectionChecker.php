<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Connection;

use Crehler\PaymentBundle\Application\DTO\Connection\ConnectionCheckResult;
use Crehler\PaymentBundle\Infrastructure\Connection\AbstractGatewayConnectionChecker;
use Crehler\PayNow\Infrastructure\Client\PayNowExceptionDescriber;
use Paynow\{Client, Environment};
use Paynow\Service\Payment;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

/**
 * Verifies PayNow API credentials by issuing a lightweight authenticated call
 * (getPaymentMethods). Builds the client from the values the operator typed in the
 * form (falling back to stored config for untouched fields), so the test reflects
 * the unsaved state of whichever environment's card the button sits in.
 */
final class PayNowConnectionChecker extends AbstractGatewayConnectionChecker
{
    /**
     * @var string
     */
    private const CONFIG_DOMAIN = 'CrehlerPayNow.config';

    public function __construct(
        SystemConfigService $systemConfigService,
        private readonly PayNowExceptionDescriber $exceptionDescriber,
    ) {
        parent::__construct($systemConfigService);
    }

    public function check(string $environment, array $config, ?string $salesChannelId): ConnectionCheckResult
    {
        $sandbox = $environment === 'sandbox';

        $apiKey = $this->resolveValue($config, $sandbox ? 'sandboxApiKey' : 'apiKey', $salesChannelId);
        $signatureKey = $this->resolveValue(
            $config,
            $sandbox ? 'sandboxSignatureKeyLabel' : 'signatureKeyLabel',
            $salesChannelId,
        );

        if ($apiKey === '' || $signatureKey === '') {
            return ConnectionCheckResult::failure('API key and signature key are required.');
        }

        try {
            $client = new Client($apiKey, $signatureKey, $sandbox ? Environment::SANDBOX : Environment::PRODUCTION);
            (new Payment($client))->getPaymentMethods('PLN', 100);
        } catch (Throwable $e) {
            return ConnectionCheckResult::failure($this->exceptionDescriber->describe($e));
        }

        return ConnectionCheckResult::ok('Connection successful.');
    }

    protected function configDomain(): string
    {
        return self::CONFIG_DOMAIN;
    }
}
