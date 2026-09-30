<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Dto\Transaction;

/**
 * PayNow's buyer.address, which is a pair rather than an address: `billing` and `shipping`
 * are separate objects and a payment can carry either, both, or neither.
 *
 * Shipping is nullable because an order without a delivery has no shipping address, and
 * repeating the billing one under a name it does not mean would tell PayPo's underwriting
 * that the goods go where the invoice goes — a claim nobody made.
 */
final readonly class TransactionBuyerAddressDto
{
    public function __construct(
        private TransactionAddressDto $billing,
        private ?TransactionAddressDto $shipping = null,
    ) {
    }

    public function getBilling(): TransactionAddressDto
    {
        return $this->billing;
    }

    public function getShipping(): ?TransactionAddressDto
    {
        return $this->shipping;
    }
}
