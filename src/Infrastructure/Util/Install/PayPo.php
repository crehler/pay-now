<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Infrastructure\Util\Install;

use Crehler\PaymentBundle\Infrastructure\Util\Lifecycle\{ShopwarePaymentMethod, ShopwarePaymentMethodDescription};
use Crehler\PayNowPayment\Constant\Methods;
use Crehler\PayNowPayment\Handler\PayPoHandler;

/**
 * PayPo is its own payment method, not a channel under the bank transfer.
 *
 * It arrives from PayNow under type PAYPO — a sibling of PBL in the same response, never a
 * member of it — so it can never appear in the bank chooser. The gateway also gives it a
 * fixed id (3000) where every bank id is variable, which is the other half of the same fact:
 * PayPo is a product, not a channel.
 *
 * Needs activating on the merchant's side first (Settings > Shops and poses > Activate PayPo),
 * and in White Label mode PayNow additionally requires the shop to display their GDPR clause
 * — which PayNowConsentProvider already renders as a required checkbox.
 */
final class PayPo extends ShopwarePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            handlerIdentifier: PayPoHandler::class,
            position: 4,
            technicalName: Methods::PAYPO_NAME,
            translations: [
                new ShopwarePaymentMethodDescription(
                    language: 'pl-PL',
                    name: 'PayPo — kup teraz, zapłać później',
                    description: 'Odbierz zamówienie i zapłać do 30 dni później lub rozłóż na raty. Obsługiwane przez PayNow.'
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'en-GB',
                    name: 'PayPo — buy now, pay later',
                    description: 'Receive your order and pay up to 30 days later, or in instalments. Powered by PayNow.'
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'de-DE',
                    name: 'PayPo — jetzt kaufen, später bezahlen',
                    description: 'Erhalten Sie Ihre Bestellung und zahlen Sie bis zu 30 Tage später oder in Raten. Powered by PayNow.'
                ),
            ],
            afterOrderEnabled: true,
            iconName: 'paypo',
        );
    }
}
