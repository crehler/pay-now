<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Dto\Transaction;

final readonly class TransactionBuyerPhoneDto
{
    public function __construct(
        private ?string $prefix,
        private ?string $number,
    ) {
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }
}
