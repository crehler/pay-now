<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Tests\Unit\Enums;

use Crehler\PayNowPayment\Enums\PayNowOrderStatusesEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function sprintf;

/**
 * The status vocabulary has to match the SDK's, because a status this enum cannot parse is
 * a status nothing acts on: the notification subscriber returns NONE and the handlers treat
 * the payment as still running. Both are silent, and both leave the order open forever.
 */
final class PayNowOrderStatusesEnumTest extends TestCase
{
    /**
     * The one that was actually broken. The SDK spells it CANCELLED, this enum spelled it
     * CANCEL, and nothing bridged the two — so a payment the customer cancelled at PayNow
     * parsed as nothing at all and the transaction never reached a terminal state.
     */
    public function testTheSdksSpellingOfCancelledIsUnderstood(): void
    {
        $cancelled = PayNowOrderStatusesEnum::tryFrom('CANCELLED');

        self::assertNotNull($cancelled, 'CANCELLED is the spelling the SDK uses');
        self::assertTrue($cancelled->isFailure());

        // The older spelling keeps working — whichever one the gateway sends is handled.
        self::assertTrue(PayNowOrderStatusesEnum::CANCEL->isFailure());
    }

    /**
     * Every constant in the SDK's Status class must parse here. This is the assertion that
     * would have caught CANCELLED before it shipped.
     */
    #[DataProvider('sdkStatuses')]
    public function testEverySdkStatusIsPartOfTheVocabulary(string $status): void
    {
        self::assertNotNull(
            PayNowOrderStatusesEnum::tryFrom($status),
            sprintf('The SDK can send "%s" and this enum does not know it', $status)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sdkStatuses(): array
    {
        $constants = (new ReflectionClass(\Paynow\Model\Payment\Status::class))->getConstants();

        $cases = [];
        foreach ($constants as $value) {
            $cases[(string) $value] = [(string) $value];
        }

        return $cases;
    }

    /**
     * A freshly created redirect payment comes back as NEW, so treating it as "in flight"
     * rather than "unknown" is what keeps the unmapped-status warning meaningful — and what
     * stops BLIK's old `!== CONFIRMED` test from failing a T6 authorization.
     */
    public function testAPaymentStillRunningIsNeitherFailureNorSuccess(): void
    {
        foreach ([
            PayNowOrderStatusesEnum::NEW,
            PayNowOrderStatusesEnum::PENDING,
            PayNowOrderStatusesEnum::WAITING_FOR_CONFIRMATION,
        ] as $status) {
            self::assertTrue($status->isInFlight(), $status->value);
            self::assertFalse($status->isFailure(), $status->value);
        }
    }

    public function testConfirmedIsNeitherFailureNorInFlight(): void
    {
        self::assertFalse(PayNowOrderStatusesEnum::CONFIRMED->isFailure());
        self::assertFalse(PayNowOrderStatusesEnum::CONFIRMED->isInFlight());
    }

    /**
     * Guards the split itself: every case is exactly one of failure, in flight, or
     * confirmed. A case added later without a decision would land in none of them and be
     * silently treated as "keep waiting".
     */
    public function testEveryCaseIsClassified(): void
    {
        $unclassified = array_map(
            static fn (PayNowOrderStatusesEnum $case): string => $case->value,
            array_filter(
                PayNowOrderStatusesEnum::cases(),
                static fn (PayNowOrderStatusesEnum $case): bool => !$case->isFailure()
                    && !$case->isInFlight()
                    && $case !== PayNowOrderStatusesEnum::CONFIRMED
            )
        );

        self::assertSame([], array_values($unclassified), 'Statuses with no outcome decision');
    }

    public function testUnknownStatusesStayUnparsed(): void
    {
        self::assertNull(PayNowOrderStatusesEnum::tryFrom('SOMETHING_PAYNOW_ADDS_LATER'));
        self::assertFalse(in_array('', array_map(
            static fn (PayNowOrderStatusesEnum $case): string => $case->value,
            PayNowOrderStatusesEnum::cases()
        ), true));
    }
}
