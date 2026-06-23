<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Infrastructure\Util\Install;

use Crehler\PaymentBundle\Domain\Port\PaymentGatewayCurrencyProviderInterface;
use Crehler\PayNow\Constant\Currency;

final readonly class SupportCurrency implements PaymentGatewayCurrencyProviderInterface
{
    /**
     * @var string
     */
    private const ID = '0190593661c6753bb92a998a89c0fc5f';
    /**
     * @var string
     */
    private const PAYNOW = 'paynow';

    public function getGatewayIdentifier(): string
    {
        return self::PAYNOW;
    }

    public function getRuleId(): string
    {
        return self::ID;
    }

    public function getSupportedCurrencyIsoCodes(): array
    {
        return Currency::ALL;
    }

    public function getTranslations(): array
    {
        return [
            'en-GB' => [
                'name' => 'PayNow - Supported currencies',
                'description' => 'This rule was automatically added by the PayNow payment plugin, it represents all currencies supported by PayNow and should be assigned to PayNow payment methods.',
            ],
            'pl-PL' => [
                'name' => 'PayNow - Obsługiwane waluty',
                'description' => 'Ta reguła została automatycznie dodana przez wtyczkę do obsługi płatności PayNow, reprezentuje wszystkie waluty obsługiwane przez PayNow i powinna być przypisana do metod płatności PayNow.',
            ],
            'de-DE' => [
                'name' => 'PayNow - Unterstützte Währungen',
                'description' => 'Diese Regel wurde automatisch vom PayNow-Zahlungs-Plugin hinzugefügt. Sie repräsentiert alle von PayNow unterstützten Währungen und sollte den PayNow-Zahlungsmethoden zugewiesen werden.',
            ],
        ];
    }
}
