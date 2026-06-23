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
use Crehler\PaymentBundle\Domain\Entity\Order\BillingAddress;
use Crehler\PaymentBundle\Domain\Entity\OrderTransaction\OrderTransaction;
use Crehler\PaymentBundle\Domain\ValueObjects\LineItem;
use Crehler\PayNow\Dto\Transaction\{TransactionBuyerDto, TransactionBuyerPhoneDto, TransactionDto, TransactionOrderDto};

use function array_map;

final readonly class TransactionDtoFactory
{
    /**
     * @var string
     */
    private const CONFIG_DOMAIN = 'CrehlerPayNow.config';

    public function __construct(
        private PaymentRequestDtoFactory $paymentRequestDtoFactory,
        private TransactionDescriptionRenderer $descriptionRenderer,
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
            validityTime: 3600,
            paymentMethodId: $paymentMethodId,
            paymentMethodToken: $paymentMethodToken,
            authorizationCode: $authorizationCode,
        );
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

    private function createBuyer(
        Customer $customer,
        BillingAddress $billingAddress,
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
            locale: '',
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
