<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Factory;

use Crehler\PaymentBundle\Application\DTO\PaymentRequest\OrderItemDTO;
use Crehler\PaymentBundle\Application\Factory\PaymentRequestDtoFactory;
use Crehler\PaymentBundle\Application\Service\TransactionDescriptionRenderer;
use Crehler\PaymentBundle\Domain\Entity\Customer;
use Crehler\PaymentBundle\Domain\Entity\Order\{BillingAddress, ShippingAddress};
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\PaymentBundle\Domain\ValueObjects\{LineItem, StreetAddress};
use Crehler\PaymentBundle\Shared\Serializer;
use Crehler\PayNow\Dto\Transaction\{TransactionAddressDto,
    TransactionBuyerAddressDto,
    TransactionBuyerDto,
    TransactionBuyerPhoneDto,
    TransactionDto,
    TransactionOrderDto};
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

use function array_map;
use function max;
use function min;

final readonly class TransactionDtoFactory
{
    /**
     * @var string
     */
    private const CONFIG_DOMAIN = 'CrehlerPayNow.config';

    /**
     * PayNow's own default when the field is left unconfigured — matches the value
     * this factory sent unconditionally before the setting became configurable.
     */
    private const DEFAULT_VALIDITY_TIME_SECONDS = 3600;

    public function __construct(
        private PaymentRequestDtoFactory $paymentRequestDtoFactory,
        private TransactionDescriptionRenderer $descriptionRenderer,
        private SystemConfigService $systemConfigService,
    ) {
    }

    public function createTransactionDto(
        OrderTransaction $orderTransaction,
        string $returnUrl,
        string $transactionId,
        ?string $paymentMethodId = null,
        ?string $paymentMethodToken = null,
        ?string $authorizationCode = null,
    ): TransactionDto {
        $order = $orderTransaction->order;

        // The OrderTransaction amount (not the order total) is the source of
        // truth: hybrid payments like Trade Credit + PayNow charge only the
        // remaining balance owed on this transaction.
        $transactionAmount = $orderTransaction->totalAmount->amount;

        $buyer = $this->createBuyer(
            customer: $order->customer,
            billingAddress: $order->billingAddress,
            shippingAddress: $order->shippingAddress,
            locale: $order->locale,
        );

        $lineItems = $this->createLineItems($order->lineItems, $transactionAmount);

        return new TransactionDto(
            amount: $transactionAmount,
            currency: $order->currencyCode,
            externalId: $transactionId,
            description: $this->descriptionRenderer->render(self::CONFIG_DOMAIN, $order, $order->salesChannelId),
            continueUrl: $returnUrl,
            buyer: $buyer,
            orderItems: $lineItems,
            validityTime: $this->resolveValidityTime($order->salesChannelId),
            paymentMethodId: $paymentMethodId,
            paymentMethodToken: $paymentMethodToken,
            authorizationCode: $authorizationCode,
        );
    }

    /**
     * The JSON PayNow actually receives — null fields dropped, not sent as null.
     *
     * It lives here rather than in each handler because the payload shape is this factory's
     * contract, and because dropping nulls stopped being cosmetic once the address arrived:
     * a street line with no number yields houseNumber = null, and PayNow validates that
     * field's length and pattern on whatever reaches it. An absent optional field is what
     * "not provided" looks like in this API; an explicit null is a value that fails.
     *
     * The fields that were already nullable — paymentMethodId, paymentMethodToken,
     * authorizationCode — mean exactly the same absent as they did when sent as null: a
     * payment with no paymentMethodId is the one that lands on PayNow's own paywall.
     *
     * @return array<string, mixed>
     */
    public function normalize(TransactionDto $transactionDto): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = Serializer::getSerializer()->normalize(
            $transactionDto,
            Serializer::JSON_FORMAT,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        return $normalized;
    }

    /**
     * PayNow rejects the request outside its own [60, 864000] seconds bounds — clamp
     * defensively so an operator typo (or a config left over from a looser future
     * limit) never turns into a hard API error on checkout.
     */
    private function resolveValidityTime(?string $salesChannelId): int
    {
        $configured = $this->systemConfigService->getInt(self::CONFIG_DOMAIN . '.validityTime', $salesChannelId);
        $validityTime = $configured > 0 ? $configured : self::DEFAULT_VALIDITY_TIME_SECONDS;

        return max(60, min($validityTime, 864000));
    }

    /**
     * Build PayNow order items via the shared bundle factory so the line-item total
     * is reconciled to the transaction amount with a single explicit adjustment item
     * (handles diffs larger than one minor unit from rounding/discounts/hybrid
     * payments), then map the gateway-agnostic items to PayNow's DTO shape.
     *
     * @param array<LineItem> $lineItems
     *
     * @return array<TransactionOrderDto>
     */
    private function createLineItems(array $lineItems, int $transactionAmount): array
    {
        $orderItems = $this->paymentRequestDtoFactory->createOrderItems($lineItems);
        $reconciled = $this->paymentRequestDtoFactory->reconcileItemsTotal($orderItems, $transactionAmount);

        return array_map(
            static fn (OrderItemDTO $item) => new TransactionOrderDto(
                name: $item->name,
                category: $item->category ?? '',
                quantity: $item->quantity,
                price: $item->getTotalPrice(),
            ),
            $reconciled,
        );
    }

    /**
     * The address goes out on EVERY payment, not only the ones that strictly need it.
     *
     * PayPo is what forced the question — it refuses to underwrite without billing and
     * shipping — but the answer is not to special-case it. The data is already in the order,
     * PayNow documents the whole buyer.address block as optional, and a payment that carries
     * the customer's address is the one their risk engine, their chargeback handling and
     * their support desk can actually work with. Sending it only for one method would mean
     * two shapes of the same request and a branch that has to stay correct forever.
     */
    private function createBuyer(
        Customer $customer,
        BillingAddress $billingAddress,
        ?ShippingAddress $shippingAddress,
        ?string $locale,
    ): TransactionBuyerDto {
        $phone = $this->createBuyerPhone(
            countryCode: $billingAddress->countryCode,
            phoneNumber: $billingAddress->phone ?? $customer->phone,
        );

        return new TransactionBuyerDto(
            email: $customer->email,
            firstName: $customer->firstName,
            lastName: $customer->lastName,
            phone: $phone,
            locale: $locale,
            address: new TransactionBuyerAddressDto(
                billing: $this->createAddress(
                    street: $billingAddress->street,
                    city: $billingAddress->city,
                    zipCode: $billingAddress->zipCode,
                    countryCode: $billingAddress->countryCode,
                ),
                // Null when the order has no delivery — a download-only cart. Repeating the
                // billing address under the shipping name would tell PayPo the goods go
                // where the invoice goes, which is a claim nobody made.
                shipping: $shippingAddress === null ? null : $this->createAddress(
                    street: $shippingAddress->street,
                    city: $shippingAddress->city,
                    zipCode: $shippingAddress->zipCode,
                    countryCode: $shippingAddress->countryCode,
                ),
            ),
        );
    }

    /**
     * Shopware keeps the street as one line and PayNow wants street, houseNumber and
     * apartmentNumber apart, so the split comes from the bundle's StreetAddress — where it
     * is tested, and where the next gateway will find it.
     */
    private function createAddress(
        string $street,
        string $city,
        ?string $zipCode,
        string $countryCode,
    ): TransactionAddressDto {
        $parsed = StreetAddress::fromLine($street);

        return new TransactionAddressDto(
            street: $parsed->street,
            city: $city,
            zipcode: StreetAddress::normalizeZipCode($zipCode),
            country: $countryCode,
            houseNumber: $parsed->houseNumber,
            apartmentNumber: $parsed->apartmentNumber,
        );
    }

    private function createBuyerPhone(string $countryCode, ?string $phoneNumber): TransactionBuyerPhoneDto
    {
        $phone = $this->paymentRequestDtoFactory->parsePhoneNumber(
            countryCode: $countryCode,
            phoneNumber: $phoneNumber,
        );

        return new TransactionBuyerPhoneDto(
            prefix: $phone['prefix'],
            number: $phone['number'],
        );
    }
}
