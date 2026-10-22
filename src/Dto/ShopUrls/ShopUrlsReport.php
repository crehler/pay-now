<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Dto\ShopUrls;

use function array_map;

/**
 * What setting the shop URLs did: the addresses used (null when the shop has no
 * storefront domain) and one result per PayNow environment.
 */
final readonly class ShopUrlsReport
{
    /**
     * @param list<ShopUrlsResult> $results
     */
    public function __construct(
        public ?ShopUrls $urls,
        public array $results,
    ) {
    }

    public function hasFailure(): bool
    {
        foreach ($this->results as $result) {
            if ($result->status === ShopUrlsResult::STATUS_FAILED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{urls: array{domainUrl: string, notificationUrl: string, returnUrl: string}|null, results: list<array{environment: string, status: string, message: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'urls' => $this->urls?->toArray(),
            'results' => array_map(static fn (ShopUrlsResult $result): array => $result->toArray(), $this->results),
        ];
    }
}
