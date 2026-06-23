<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Dto\Transaction;

final readonly class TransactionBuyerDto
{
    public function __construct(
        private string $email,
        private string $firstName,
        private string $lastName,
        private TransactionBuyerPhoneDto $phone,
        private string $locale,
        private ?string $deviceFingerprint = null,
    ) {
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getPhone(): TransactionBuyerPhoneDto
    {
        return $this->phone;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getDeviceFingerprint(): ?string
    {
        return $this->deviceFingerprint;
    }
}
