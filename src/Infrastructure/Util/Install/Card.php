<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Infrastructure\Util\Install;

use Crehler\PaymentBundle\Infrastructure\Util\Lifecycle\{ShopwarePaymentMethod, ShopwarePaymentMethodDescription};
use Crehler\PayNow\Constant\Methods;
use Crehler\PayNow\Handler\CardHandler;

final class Card extends ShopwarePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            handlerIdentifier: CardHandler::class,
            position: 2,
            technicalName: Methods::CARD_NAME,
            translations: [
                new ShopwarePaymentMethodDescription(
                    language: 'pl-PL',
                    name: 'Karta',
                    description: 'Płatność kartą kredytową lub debetową. Obsługiwane przez PayNow.'
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'en-GB',
                    name: 'Card',
                    description: 'Credit or debit card payment. Powered by PayNow.'
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'de-DE',
                    name: 'Karte',
                    description: 'Kredit- oder Debitkartenzahlung. Powered by PayNow.'
                ),
            ],
            afterOrderEnabled: true,
            iconName: 'card',
        );
    }
}
