<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Dto\ShopUrls;

/**
 * Outcome of setting the shop URLs for one PayNow environment (live or sandbox).
 */
final readonly class ShopUrlsResult
{
    public const STATUS_OK = 'ok';

    /** No credentials for this environment — nothing to configure. */
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $environment,
        public string $status,
        public ?string $message = null,
    ) {
    }

    /**
     * @return array{environment: string, status: string, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
