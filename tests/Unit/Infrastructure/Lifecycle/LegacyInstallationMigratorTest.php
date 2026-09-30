<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Tests\Unit\Infrastructure\Lifecycle;

use Closure;
use Crehler\PayNowPayment\Infrastructure\Lifecycle\LegacyInstallationMigrator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

use function in_array;
use function json_encode;
use function str_contains;

/**
 * 6.x reaches Store shops as an update of the legacy 4.x line (same technical name), so the
 * 4.x credentials must land under the 6.x keys of the environment they were used for —
 * 4.x had one key pair for whichever environment was switched on.
 */
final class LegacyInstallationMigratorTest extends TestCase
{
    private const DOMAIN = 'CrehlerPayNowPayment.config.';

    private const SALES_CHANNEL = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    public function testOnlyVersionsBelowSixAreLegacy(): void
    {
        self::assertTrue(LegacyInstallationMigrator::isLegacyVersion('4.0.4'));
        self::assertTrue(LegacyInstallationMigrator::isLegacyVersion('5.9.9'));
        self::assertFalse(LegacyInstallationMigrator::isLegacyVersion('6.0.0'));
        self::assertFalse(LegacyInstallationMigrator::isLegacyVersion('6.6.1'));
    }

    public function testCredentialKeysFollowTheEnvironment(): void
    {
        self::assertSame('apiKey', LegacyInstallationMigrator::resolveCredentialKey('ApiKey', false));
        self::assertSame('sandboxApiKey', LegacyInstallationMigrator::resolveCredentialKey('ApiKey', true));
        self::assertSame('signatureKeyLabel', LegacyInstallationMigrator::resolveCredentialKey('SingatureKeyLabel', false));
        self::assertSame('sandboxSignatureKeyLabel', LegacyInstallationMigrator::resolveCredentialKey('SingatureKeyLabel', true));
        self::assertNull(LegacyInstallationMigrator::resolveCredentialKey('WaitingTime', false));
    }

    public function testSandboxFlagIsResolvedPerScopeWithGlobalFallback(): void
    {
        $renames = $this->migrate(
            rows: [
                $this->row('sandbox-global', 'EnableSandbox', true, null),
                $this->row('key-global', 'ApiKey', 'global-key', null),
                // The sales channel overrides the environment back to production.
                $this->row('sandbox-channel', 'EnableSandbox', false, self::SALES_CHANNEL),
                $this->row('key-channel', 'ApiKey', 'channel-key', self::SALES_CHANNEL),
                $this->row('label-channel', 'SingatureKeyLabel', 'channel-label', self::SALES_CHANNEL),
            ],
            existingKeys: [],
        );

        self::assertSame([
            'sandbox-global' => self::DOMAIN . 'enableSandbox',
            'key-global' => self::DOMAIN . 'sandboxApiKey',
            'sandbox-channel' => self::DOMAIN . 'enableSandbox',
            'key-channel' => self::DOMAIN . 'apiKey',
            'label-channel' => self::DOMAIN . 'signatureKeyLabel',
        ], $renames);
    }

    public function testARerunAfterAnInterruptedMigrationKeepsTheEnvironment(): void
    {
        // First run renamed EnableSandbox and stopped before the credentials.
        $renames = $this->migrate(
            rows: [
                $this->row('sandbox-global', 'enableSandbox', true, null),
                $this->row('key-global', 'ApiKey', 'global-key', null),
            ],
            existingKeys: [self::DOMAIN . 'enableSandbox'],
        );

        self::assertSame(['key-global' => self::DOMAIN . 'sandboxApiKey'], $renames);
    }

    public function testAValueAlreadyConfiguredUnderTheNewKeyWins(): void
    {
        $renames = $this->migrate(
            rows: [$this->row('key-global', 'ApiKey', 'legacy-key', null)],
            existingKeys: [self::DOMAIN . 'apiKey'],
        );

        self::assertSame([], $renames);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $existingKeys
     *
     * @return array<string, string> row id => new configuration key
     */
    private function migrate(array $rows, array $existingKeys): array
    {
        $renames = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            static fn (Closure $func): mixed => $func($connection),
        );
        $connection->method('fetchAllAssociative')->willReturn($rows);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params): int|false => in_array($params['key'], $existingKeys, true) ? 1 : false,
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$renames): int {
                if (str_contains($sql, 'system_config')) {
                    $renames[$params['id']] = $params['key'];
                }

                return 1;
            },
        );

        (new LegacyInstallationMigrator($connection))->migrateFromLegacyLine();

        return $renames;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, string $key, mixed $value, ?string $salesChannelId): array
    {
        return [
            'id' => $id,
            'configuration_key' => self::DOMAIN . $key,
            'configuration_value' => json_encode(['_value' => $value]),
            'sales_channel_id' => $salesChannelId,
        ];
    }
}
