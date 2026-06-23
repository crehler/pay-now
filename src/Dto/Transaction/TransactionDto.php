<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Dto\Transaction;

final readonly class TransactionDto
{
    public function __construct(
        private int $amount,
        private string $currency,
        private string $externalId,
        private string $description,
        private string $continueUrl,
        private TransactionBuyerDto $buyer,
        private array $orderItems,
        private int $validityTime,
        private ?string $paymentMethodId = null,
        private ?string $paymentMethodToken = null,
        private ?string $authorizationCode = null,
    ) {
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getContinueUrl(): string
    {
        return $this->continueUrl;
    }

    public function getBuyer(): TransactionBuyerDto
    {
        return $this->buyer;
    }

    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    public function getValidityTime(): int
    {
        return $this->validityTime;
    }

    public function getPaymentMethodId(): ?string
    {
        return $this->paymentMethodId;
    }

    public function getAuthorizationCode(): ?string
    {
        return $this->authorizationCode;
    }

    public function getPaymentMethodToken(): ?string
    {
        return $this->paymentMethodToken;
    }
}
