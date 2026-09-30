<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Tests\Unit\Handler;

use Crehler\PaymentBundle\Shared\EnhancedLogger;
use Crehler\PayNowPayment\Handler\RejectsTerminalGatewayStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function basename;
use function file_get_contents;
use function glob;

/**
 * The guard that decides whether an authorization PayNow already answered is a failure.
 *
 * Before it existed only BLIK looked at the status. Bank and Card read the redirect URL and
 * nothing else, so a rejected payment produced a success with an empty redirect — which the
 * shared flow reads as "accepted, no redirect needed, the webhook finishes it". The webhook
 * never comes for a payment that is already over.
 */
final class RejectsTerminalGatewayStatusTest extends TestCase
{
    #[DataProvider('terminalFailures')]
    public function testATerminalFailureBecomesAFailedPayment(string $status): void
    {
        $result = $this->guard()->call($status, 'bank transfer');

        self::assertNotNull($result, $status);
        self::assertFalse($result->success);
        self::assertStringContainsString($status, (string) $result->errorMessage);
        self::assertStringContainsString('bank transfer', (string) $result->errorMessage);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function terminalFailures(): array
    {
        return [
            'rejected' => ['REJECTED'],
            'cancel' => ['CANCEL'],
            'cancelled' => ['CANCELLED'],
            'error' => ['ERROR'],
            'expired' => ['EXPIRED'],
            'abandoned' => ['ABANDONED'],
        ];
    }

    /**
     * Null means "carry on": the handler returns its own success and the transaction stays
     * in progress until the notification arrives.
     */
    #[DataProvider('nonFailures')]
    public function testAnythingElseLetsThePaymentContinue(string $status): void
    {
        self::assertNull($this->guard()->call($status, 'card'), $status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonFailures(): array
    {
        return [
            'confirmed' => ['CONFIRMED'],
            'new' => ['NEW'],
            'pending' => ['PENDING'],
            'waiting' => ['WAITING_FOR_CONFIRMATION'],
            // A status the gateway adds later must not start failing payments on its own.
            'unknown' => ['SOMETHING_PAYNOW_ADDS_LATER'],
            'empty' => [''],
        ];
    }

    public function testTheStatusIsMatchedCaseInsensitively(): void
    {
        self::assertNotNull($this->guard()->call('rejected', 'BLIK'));
    }

    /**
     * Structural: a handler added later must opt in, or it silently reintroduces the hole.
     * BLIK is included — it had the check first, and the shared trait replaced it.
     */
    public function testEveryPaymentHandlerUsesTheGuard(): void
    {
        $handlers = glob(__DIR__ . '/../../../src/Handler/*Handler.php');
        self::assertNotEmpty($handlers);

        foreach ($handlers as $file) {
            self::assertStringContainsString(
                'use RejectsTerminalGatewayStatus;',
                (string) file_get_contents($file),
                basename($file) . ' does not check the status PayNow answered with'
            );
        }
    }

    private function guard(): object
    {
        return new class(new EnhancedLogger(new NullLogger())) {
            use RejectsTerminalGatewayStatus;

            public function __construct(private readonly EnhancedLogger $logger)
            {
            }

            public function call(?string $status, string $label): mixed
            {
                return $this->failIfGatewayRejected($status, $label);
            }
        };
    }
}
