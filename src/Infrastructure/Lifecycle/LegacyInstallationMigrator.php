<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Infrastructure\Lifecycle;

use DateTimeImmutable;
use Doctrine\DBAL\{ArrayParameterType, Connection};
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

use function bin2hex;
use function is_array;
use function json_decode;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function substr;
use function version_compare;

/**
 * Carries a shop over from earlier installations of PayNow to this one.
 *
 * Two predecessors share this plugin's code base but not its data layout:
 *
 * 1. The legacy 4.x line (repo crehler-com/PayNow) — the one published in the Shopware Store.
 *    It has the SAME technical name and base class (Crehler\PayNowPayment\CrehlerPayNowPayment),
 *    so Shopware runs 6.x as an ordinary update() of it. Nothing of 4.x survives in code:
 *    - its single payment method points at a handler class 6.x no longer has; left active,
 *      checkout would offer a method nobody can process;
 *    - its credentials live under other keys (ApiKey, SingatureKeyLabel, EnableSandbox) and
 *      one key pair served whichever environment was on, where 6.x keeps production and
 *      sandbox credentials apart.
 *    The 4.x tables (paynow_payment_tokens, paynow_refund_history) are left alone — they
 *    hold the history of past orders. paynow_idempotency_key has an identical schema in
 *    both lines and is reused as is.
 *
 * 2. Early 6.x builds published as technical name CrehlerPayNow. That is a different plugin
 *    for Shopware, so install() starts from scratch; payment methods are taken over by the
 *    bundle (matched by technical name), the configuration is copied here.
 *
 * Raw DBAL on purpose: system_config.configuration_key uses a case-insensitive collation,
 * and 4.x "ApiKey" vs 6.x "apiKey" must be told apart — hence BINARY comparisons.
 */
final readonly class LegacyInstallationMigrator
{
    public const LEGACY_HANDLER_IDENTIFIER = 'Crehler\\PayNowPayment\\Service\\PayNowService';

    private const DOMAIN = 'CrehlerPayNowPayment.config.';

    private const RENAMED_DOMAIN = 'CrehlerPayNow.config.';

    private const LEGACY_SANDBOX_KEY = 'EnableSandbox';

    private const SANDBOX_KEY = 'enableSandbox';

    /**
     * 4.x credential key => [6.x production key, 6.x sandbox key].
     */
    private const LEGACY_CREDENTIAL_KEYS = [
        'ApiKey' => ['apiKey', 'sandboxApiKey'],
        'SingatureKeyLabel' => ['signatureKeyLabel', 'sandboxSignatureKeyLabel'],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public static function isLegacyVersion(string $version): bool
    {
        return version_compare($version, '6.0.0', '<');
    }

    /**
     * The 6.x key a 4.x credential moves to — production or sandbox, depending on which
     * environment the 4.x installation had switched on for the same scope.
     */
    public static function resolveCredentialKey(string $legacyKey, bool $sandbox): ?string
    {
        $targets = self::LEGACY_CREDENTIAL_KEYS[$legacyKey] ?? null;

        return $targets === null ? null : $targets[$sandbox ? 1 : 0];
    }

    /**
     * Retry-safe: everything runs in one transaction, and the environment marker is read
     * case-insensitively, so a rerun after a partial migration (EnableSandbox already renamed
     * to enableSandbox, credentials not yet) still sends the credentials to the right keys.
     */
    public function migrateFromLegacyLine(): void
    {
        $this->connection->transactional(function (): void {
            $this->connection->executeStatement(
                'UPDATE `payment_method` SET `active` = 0 WHERE `handler_identifier` = :handler',
                ['handler' => self::LEGACY_HANDLER_IDENTIFIER],
            );

            $rows = $this->connection->fetchAllAssociative(
                'SELECT `id`, `configuration_key`, `configuration_value`, `sales_channel_id`
                   FROM `system_config`
                  WHERE BINARY `configuration_key` IN (:credentialKeys)
                     OR `configuration_key` = :sandboxKey',
                [
                    'credentialKeys' => [self::DOMAIN . 'ApiKey', self::DOMAIN . 'SingatureKeyLabel'],
                    'sandboxKey' => self::DOMAIN . self::LEGACY_SANDBOX_KEY,
                ],
                ['credentialKeys' => ArrayParameterType::STRING],
            );

            $sandboxByScope = [];
            foreach ($rows as $row) {
                if (strcasecmp($row['configuration_key'], self::DOMAIN . self::LEGACY_SANDBOX_KEY) === 0) {
                    $sandboxByScope[$this->scope($row['sales_channel_id'])] = (bool) $this->decodeValue($row['configuration_value']);
                }
            }

            foreach ($rows as $row) {
                $legacyKey = substr($row['configuration_key'], strlen(self::DOMAIN));

                if (strcasecmp($legacyKey, self::LEGACY_SANDBOX_KEY) === 0) {
                    if ($legacyKey === self::LEGACY_SANDBOX_KEY) {
                        $this->renameKey($row, self::SANDBOX_KEY);
                    }

                    continue;
                }

                $sandbox = $sandboxByScope[$this->scope($row['sales_channel_id'])] ?? $sandboxByScope[$this->scope(null)] ?? false;
                $targetKey = self::resolveCredentialKey($legacyKey, $sandbox);

                if ($targetKey !== null) {
                    $this->renameKey($row, $targetKey);
                }
            }
        });
    }

    /**
     * Copy CrehlerPayNow.config.* (early 6.x technical name) into this plugin's domain,
     * never overwriting a value already set here. The source rows stay for rollback.
     */
    public function copyRenamedConfiguration(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT `configuration_key`, `configuration_value`, `sales_channel_id`
               FROM `system_config`
              WHERE `configuration_key` LIKE BINARY :prefix',
            ['prefix' => self::RENAMED_DOMAIN . '%'],
        );

        foreach ($rows as $row) {
            if (!str_starts_with($row['configuration_key'], self::RENAMED_DOMAIN)) {
                continue;
            }

            $targetKey = self::DOMAIN . substr($row['configuration_key'], strlen(self::RENAMED_DOMAIN));

            if ($this->keyExists($targetKey, $row['sales_channel_id'])) {
                continue;
            }

            $this->connection->insert('system_config', [
                'id' => Uuid::randomBytes(),
                'configuration_key' => $targetKey,
                'configuration_value' => $row['configuration_value'],
                'sales_channel_id' => $row['sales_channel_id'],
                'created_at' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renameKey(array $row, string $targetKey): void
    {
        // A value already configured under the 6.x key wins — the legacy row is then left as is.
        if ($this->keyExists(self::DOMAIN . $targetKey, $row['sales_channel_id'])) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE `system_config` SET `configuration_key` = :key WHERE `id` = :id',
            ['key' => self::DOMAIN . $targetKey, 'id' => $row['id']],
        );
    }

    private function keyExists(string $key, ?string $salesChannelId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM `system_config`
              WHERE BINARY `configuration_key` = :key AND `sales_channel_id` <=> :salesChannelId',
            ['key' => $key, 'salesChannelId' => $salesChannelId],
        ) !== false;
    }

    private function scope(?string $salesChannelId): string
    {
        return $salesChannelId === null ? 'global' : bin2hex($salesChannelId);
    }

    private function decodeValue(?string $json): mixed
    {
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? ($decoded['_value'] ?? null) : null;
    }
}
