<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Tests\Unit\Subscriber;

use Crehler\PayNowPayment\Subscriber\ShopUrlsSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Only a save carrying PayNow credentials or the sandbox switch sets the shop URLs in
 * PayNow — not every save of every plugin's configuration.
 */
final class ShopUrlsSubscriberTest extends TestCase
{
    public function testASaveWithPayNowCredentialsTriggersIt(): void
    {
        self::assertTrue(ShopUrlsSubscriber::touchesCredentials([
            'CrehlerPayNowPayment.config.sandboxApiKey' => 'key',
            'CrehlerPayNowPayment.config.validityTime' => 3600,
        ]));
    }

    public function testTheSandboxSwitchTriggersIt(): void
    {
        self::assertTrue(ShopUrlsSubscriber::touchesCredentials(['CrehlerPayNowPayment.config.enableSandbox' => false]));
    }

    public function testOtherSettingsDoNot(): void
    {
        self::assertFalse(ShopUrlsSubscriber::touchesCredentials([
            'CrehlerPayNowPayment.config.validityTime' => 3600,
            'CrehlerTpay.config.apiKey' => 'other-plugin',
        ]));
    }
}
