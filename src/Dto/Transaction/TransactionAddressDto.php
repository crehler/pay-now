<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Dto\Transaction;

/**
 * One address in PayNow's buyer.address — either the billing or the shipping half.
 *
 * Field names are PayNow's, not Shopware's: the gateway spells it `zipcode`, lower-case c,
 * and wants the house and apartment numbers as their own fields rather than inside the
 * street. The getters are what the serializer reads, so renaming one renames the JSON key.
 *
 * houseNumber, apartmentNumber and zipcode are nullable because a Shopware address does
 * not always carry them — the street line often has no number, and whole countries have no
 * postal code at all. Null is dropped from the payload rather than sent as an empty string:
 * PayNow validates the postcode against ^[A-Z0-9-_ ]+$, which an empty string fails.
 */
final readonly class TransactionAddressDto
{
    public function __construct(
        private string $street,
        private string $city,
        private ?string $zipcode,
        private string $country,
        private ?string $houseNumber = null,
        private ?string $apartmentNumber = null,
    ) {
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getZipcode(): ?string
    {
        return $this->zipcode;
    }

    /** ISO 3166-1 alpha-2, exactly two characters. */
    public function getCountry(): string
    {
        return $this->country;
    }

    public function getHouseNumber(): ?string
    {
        return $this->houseNumber;
    }

    public function getApartmentNumber(): ?string
    {
        return $this->apartmentNumber;
    }
}
