<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Tests\Unit\Services;

use Crehler\PayNowPayment\Services\PayNowConsentProvider;
use PHPUnit\Framework\TestCase;

/**
 * PayNow's data-processing notices are an information obligation, not a consent: they are
 * shown — title in one line, full text behind "Read more" — and never block the order
 * (WT-1030). The title used to be dropped, which made the text start mid-sentence.
 */
final class PayNowConsentProviderTest extends TestCase
{
    public function testTheNoticeIsInformationalAndKeepsItsTitle(): void
    {
        $consent = PayNowConsentProvider::toConsent([
            ['title' => 'Administratorem danych jest mBank S.A.', 'content' => '<p>Funkcję Inspektora…</p>', 'locale' => 'pl-PL'],
        ]);

        self::assertNotNull($consent);
        self::assertFalse($consent->requiresAcceptance);
        self::assertSame('Administratorem danych jest mBank S.A.', $consent->title);
        self::assertSame('<p>Funkcję Inspektora…</p>', $consent->content);
        self::assertSame('pl-PL', $consent->locale);
    }

    public function testEveryNoticeReachesTheModalWithItsOwnHeading(): void
    {
        $consent = PayNowConsentProvider::toConsent([
            ['title' => 'First', 'content' => '<p>one</p>', 'locale' => 'pl-PL'],
            ['title' => 'Second', 'content' => '<p>two</p>', 'locale' => 'pl-PL'],
        ]);

        self::assertNotNull($consent);
        self::assertSame('First', $consent->title);
        self::assertSame('<p>one</p><p><strong>Second</strong></p><p>two</p>', $consent->content);
    }

    public function testNoNoticesMeansNothingToShow(): void
    {
        self::assertNull(PayNowConsentProvider::toConsent([]));
    }

    public function testAnEmptyTitleFallsBackToTheGenericHeading(): void
    {
        $consent = PayNowConsentProvider::toConsent([
            ['title' => '', 'content' => '<p>body</p>', 'locale' => 'en-GB'],
        ]);

        self::assertNotNull($consent);
        self::assertNull($consent->title);
    }

    public function testCacheEntriesAreSeparatePerChannelLocaleAndEnvironment(): void
    {
        $base = PayNowConsentProvider::cacheKey('channel-a', 'pl-PL', false);

        self::assertSame($base, PayNowConsentProvider::cacheKey('channel-a', 'pl-PL', false));
        self::assertNotSame($base, PayNowConsentProvider::cacheKey('channel-b', 'pl-PL', false));
        self::assertNotSame($base, PayNowConsentProvider::cacheKey('channel-a', 'en-GB', false));
        self::assertNotSame($base, PayNowConsentProvider::cacheKey('channel-a', 'pl-PL', true));
    }
}
