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
use Crehler\PayNow\Handler\BankHandler;

final class Bank extends ShopwarePaymentMethod
{
    public function __construct()
    {
        parent::__construct(
            handlerIdentifier: BankHandler::class,
            position: 3,
            technicalName: Methods::BANK_NAME,
            translations: [
                new ShopwarePaymentMethodDescription(
                    language: 'pl-PL',
                    name: 'Przelew online',
                    description: 'Wybierz swój bank i dokonaj płatności. Obsługiwane przez PayNow.'
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'en-GB',
                    name: 'Online transfer',
                    description: 'Choose your bank and make a payment. Powered by PayNow.'
                ),
                new ShopwarePaymentMethodDescription(
                    language: 'de-DE',
                    name: 'Online-Überweisung',
                    description: 'Wählen Sie Ihre Bank und führen Sie die Zahlung durch. Powered by PayNow.'
                ),
            ],
            afterOrderEnabled: true,
            iconName: 'bank',
            subMethodsEnabled: true,
        );
    }
}
