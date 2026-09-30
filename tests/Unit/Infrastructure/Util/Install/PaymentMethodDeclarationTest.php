<?php

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

declare(strict_types=1);

namespace Crehler\PayNowPayment\Tests\Unit\Infrastructure\Util\Install;

use Crehler\PaymentBundle\Domain\Enum\PaymentType;
use Crehler\PaymentBundle\Infrastructure\Handler\AbstractPaymentMethodHandler;
use Crehler\PaymentBundle\Infrastructure\Util\Lifecycle\ShopwarePaymentMethod;
use Crehler\PayNowPayment\Constant\Methods;
use Crehler\PayNowPayment\Handler\PayPoHandler;
use Crehler\PayNowPayment\Infrastructure\Util\Install\PayPo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function basename;
use function class_exists;
use function count;
use function glob;
use function is_file;
use function is_subclass_of;
use function pathinfo;
use function sprintf;

use const PATHINFO_FILENAME;

/**
 * These classes are the ones PaymentMethodClassLocator instantiates, and it does so on
 * install, update, activate AND deactivate. When the bundle dropped the subMethodsEnabled
 * parameter and this plugin kept passing it, every one of those four operations died with
 * "Unknown named parameter" — a hard fatal on a plugin that was already active. Constructing
 * each class is the whole check, and it is the check that was missing.
 */
final class PaymentMethodDeclarationTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function installClasses(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../../../../../src/Infrastructure/Util/Install/*.php') ?: [] as $file) {
            $class = 'Crehler\\PayNowPayment\\Infrastructure\\Util\\Install\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($class) && is_subclass_of($class, ShopwarePaymentMethod::class)) {
                $cases[basename($file)] = [$class];
            }
        }

        return $cases;
    }

    /**
     * @param class-string<ShopwarePaymentMethod> $class
     */
    #[DataProvider('installClasses')]
    public function testTheLifecycleCanConstructTheDeclaration(string $class): void
    {
        $method = new $class();

        self::assertNotSame('', $method->technicalName);
        self::assertTrue(
            class_exists($method->handlerIdentifier),
            sprintf('%s names a handler that does not exist: %s', $class, $method->handlerIdentifier),
        );
        self::assertTrue(
            is_subclass_of($method->handlerIdentifier, AbstractPaymentMethodHandler::class),
            sprintf('%s names a handler that is not a payment handler', $class),
        );
    }

    /**
     * An iconName the installer cannot resolve leaves the method with no image at all, and
     * nothing in the install path complains about it.
     *
     * @param class-string<ShopwarePaymentMethod> $class
     */
    #[DataProvider('installClasses')]
    public function testTheDeclaredIconExists(string $class): void
    {
        $method = new $class();

        if ($method->iconName === null) {
            self::markTestSkipped($class . ' declares no icon');
        }

        $iconDir = __DIR__ . '/../../../../../src/Resources/icons/';
        $found = false;
        foreach (['png', 'svg', 'jpg', 'jpeg', 'webp'] as $extension) {
            if (is_file($iconDir . pathinfo($method->iconName, PATHINFO_FILENAME) . '.' . $extension)) {
                $found = true;

                break;
            }
        }

        self::assertTrue($found, sprintf('%s declares icon "%s" but no such file exists', $class, $method->iconName));
    }

    public function testTechnicalNamesAreUnique(): void
    {
        $names = array_map(static fn (array $case) => (new $case[0]())->technicalName, self::installClasses());

        self::assertCount(count($names), array_unique($names), 'two payment methods share a technical name');
    }

    /**
     * PayPo arrives from PayNow under its own type, never inside the PBL group, so it is a
     * payment method rather than a bank on the chooser — and it carries a fixed gateway id
     * where bank ids are variable.
     */
    public function testPayPoIsADeferredMethodWithNoChannelsAndAFixedGatewayId(): void
    {
        $payPo = new PayPo();

        self::assertSame(Methods::PAYPO_NAME, $payPo->technicalName);
        self::assertSame(PayPoHandler::class, $payPo->handlerIdentifier);
        self::assertSame(PaymentType::DEFERRED, PayPoHandler::paymentType());
        self::assertFalse(PayPoHandler::usesGatewayChannels());
        self::assertSame('3000', Methods::PAYPO_GATEWAY_ID);
    }
}
