<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Constant;

final class Currency
{
    /**
     * @var string
     */
    public const PLN = 'PLN';
    /**
     * @var string
     */
    public const EUR = 'EUR';
    /**
     * @var string
     */
    public const USD = 'USD';
    /**
     * @var string
     */
    public const GBP = 'GBP';
    /**
     * @var string
     */
    public const CZK = 'CZK';

    /**
     * @var string
     */
    public const DEFAULT = self::PLN;

    /**
     * Single source of truth for the currencies PayNow supports — consumed by
     * {@see \Crehler\PayNow\Infrastructure\Util\Install\SupportCurrency}
     * so the install rule and this list cannot drift apart.
     *
     * @var array<int, string>
     */
    public const ALL = [
        self::PLN,
        self::EUR,
        self::USD,
        self::GBP,
        self::CZK,
    ];
}
