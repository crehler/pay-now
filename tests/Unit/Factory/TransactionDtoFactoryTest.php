<?php

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

declare(strict_types=1);

namespace Crehler\PayNow\Tests\Unit\Factory;

use Crehler\PaymentBundle\Application\Factory\PaymentRequestDtoFactory;
use Crehler\PaymentBundle\Application\Service\TransactionDescriptionRenderer;
use Crehler\PaymentBundle\Domain\Entity\Customer;
use Crehler\PaymentBundle\Domain\Entity\Order\{BillingAddress, Order, ShippingAddress};
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\{OrderTransaction, PaymentMethod, PaymentStatus};
use Crehler\PaymentBundle\Domain\ValueObjects\Money;
use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\PayNow\Factory\TransactionDtoFactory;
use Crehler\PayNow\Handler\BankHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The buyer address goes out on every payment, not only on the one method that needs it.
 *
 * PayPo is what forced the question — it will not underwrite without billing and shipping —
 * but special-casing it would mean two shapes of the same request and a branch that has to
 * stay correct forever. These cases pin the shape that actually reaches PayNow, which is the
 * only thing that can be checked without live credentials.
 */
final class TransactionDtoFactoryTest extends TestCase
{
    public function testEveryPaymentCarriesTheBillingAddress(): void
    {
        $payload = $this->payload();

        self::assertArrayHasKey('address', $payload['buyer']);
        self::assertSame('Warszawa', $payload['buyer']['address']['billing']['city']);
        self::assertSame('PL', $payload['buyer']['address']['billing']['country']);
    }

    /**
     * Shopware keeps "Kwiatowa 5/3" in one field and PayNow wants three.
     */
    public function testTheStreetLineIsSplitIntoTheGatewaysThreeFields(): void
    {
        $billing = $this->payload()['buyer']['address']['billing'];

        self::assertSame('Kwiatowa', $billing['street']);
        self::assertSame('5', $billing['houseNumber']);
        self::assertSame('3', $billing['apartmentNumber']);
    }

    public function testTheZipCodeIsUpperCasedBecauseTheGatewayRejectsLowerCase(): void
    {
        // PayNow validates against ^[A-Z0-9-_ ]+$ — a field the customer never sees.
        self::assertSame('00-950', $this->payload()['buyer']['address']['billing']['zipcode']);
    }

    public function testTheShippingAddressIsSentAndIsNotTheBillingOne(): void
    {
        $address = $this->payload()['buyer']['address'];

        self::assertSame('Dostawcza', $address['shipping']['street']);
        self::assertSame('7', $address['shipping']['houseNumber']);
        self::assertSame('Gdańsk', $address['shipping']['city']);
        self::assertNotSame($address['billing']['street'], $address['shipping']['street']);
    }

    /**
     * An order with no delivery has no shipping address, and the key is absent rather than
     * null. Repeating the billing address here would tell PayPo's underwriting that the
     * goods go where the invoice goes — a claim nobody made.
     */
    public function testAnOrderWithoutADeliverySendsNoShippingAddressAtAll(): void
    {
        $address = $this->payload(withDelivery: false)['buyer']['address'];

        self::assertArrayHasKey('billing', $address);
        self::assertArrayNotHasKey('shipping', $address);
    }

    /**
     * An absent optional field is what "not provided" looks like in this API; an explicit
     * null is a value that fails its own length and pattern checks. A street with no number
     * yields houseNumber = null, which is exactly the case that made this matter.
     */
    public function testNullFieldsAreDroppedRatherThanSentAsNull(): void
    {
        $payload = $this->payload(street: 'Kwiatowa');

        self::assertArrayNotHasKey('houseNumber', $payload['buyer']['address']['billing']);
        self::assertArrayNotHasKey('apartmentNumber', $payload['buyer']['address']['billing']);

        // The fields that were already nullable mean the same absent as before: a payment
        // with no paymentMethodId is the one that lands on PayNow's own paywall.
        self::assertArrayNotHasKey('paymentMethodId', $payload);
        self::assertArrayNotHasKey('authorizationCode', $payload);
    }

    /**
     * PayNow documents locale as BCP47 with pl-PL as the default. The plugin used to send
     * an empty string, which is not a tag at all — it simply was not rejected.
     */
    public function testTheLocaleIsTheOrdersOwnLanguageTag(): void
    {
        self::assertSame('pl-PL', $this->payload()['buyer']['locale']);
    }

    /**
     * An order with no language leaves the field out, so PayNow applies its own default
     * instead of validating an empty string.
     */
    public function testAnOrderWithoutALanguageSendsNoLocaleAtAll(): void
    {
        self::assertArrayNotHasKey('locale', $this->payload(locale: null)['buyer']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $street = 'Kwiatowa 5/3',
        bool $withDelivery = true,
        ?string $locale = 'pl-PL',
    ): array {
        $shippingAddress = null;

        if ($withDelivery) {
            $shippingAddress = new ShippingAddress(
                id: '019ed0356345700000000000000000d1',
                firstName: 'Jan',
                lastName: 'Kowalski',
                street: 'Dostawcza 7',
                city: 'Gdańsk',
                zipCode: '80-001',
                countryCode: 'PL',
                countryName: 'Polska',
            );
        }

        $factory = $this->factory();
        $dto = $factory->createTransactionDto(
            orderTransaction: $this->orderTransaction($street, $shippingAddress, $locale),
            returnUrl: 'https://shop.example.com/return',
            transactionId: '019ed0356345700000000000000000d2',
        );

        return $factory->normalize($dto);
    }

    private function factory(): TransactionDtoFactory
    {
        $systemConfigService = $this->createStub(SystemConfigService::class);
        $systemConfigService->method('getString')->willReturn('{{ orderNumber }}');
        $systemConfigService->method('getInt')->willReturn(3600);

        return new TransactionDtoFactory(
            new PaymentRequestDtoFactory(new EnhancedLogger(new NullLogger())),
            new TransactionDescriptionRenderer(
                $systemConfigService,
                $this->createStub(EntityRepository::class),
                new EventDispatcher(),
                new NullLogger(),
            ),
            $systemConfigService,
        );
    }

    private function orderTransaction(
        string $street,
        ?ShippingAddress $shippingAddress,
        ?string $locale,
    ): OrderTransaction
    {
        $order = new Order(
            id: '019ed0356345700000000000000000d3',
            orderNumber: '10001',
            totalAmount: new Money(12345, 'PLN'),
            netAmount: new Money(10037, 'PLN'),
            shippingAmount: new Money(0, 'PLN'),
            currencyCode: 'PLN',
            customer: new Customer(
                id: '019ed0356345700000000000000000d4',
                customerNumber: '10001',
                email: 'jan.kowalski@example.com',
                firstName: 'Jan',
                lastName: 'Kowalski',
            ),
            billingAddress: new BillingAddress(
                id: '019ed0356345700000000000000000d5',
                firstName: 'Jan',
                lastName: 'Kowalski',
                street: $street,
                city: 'Warszawa',
                zipCode: '00-950',
                countryCode: 'PL',
                countryName: 'Polska',
                phone: '+48123456789',
            ),
            lineItems: [],
            salesChannelId: '019ed035634570b09e40580c5ba4654a',
            shippingAddress: $shippingAddress,
            locale: $locale,
        );

        return new OrderTransaction(
            id: '019ed0356345700000000000000000d6',
            paymentMethod: new PaymentMethod(
                id: '019ed0356345700000000000000000d7',
                handlerIdentifier: BankHandler::class,
                active: true,
                technicalName: 'paynow_bank',
            ),
            paymentStatus: new PaymentStatus(
                stateId: '019ed0356345700000000000000000d8',
                name: 'open',
            ),
            totalAmount: new Money(12345, 'PLN'),
            order: $order,
        );
    }
}
