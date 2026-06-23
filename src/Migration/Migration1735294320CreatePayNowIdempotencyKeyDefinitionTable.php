<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1735294320CreatePayNowIdempotencyKeyDefinitionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1735294320;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `paynow_idempotency_key` (
            `id` BINARY(16) NOT NULL,
            `transaction_id` BINARY(16) NOT NULL,
            `transaction_version_id` BINARY(16) NOT NULL,
            `idempotency_key` VARCHAR(255) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            KEY `fk.paynow_ik.transaction_id` (`transaction_id`,`transaction_version_id`),
            CONSTRAINT `fk.paynow_ik.transaction_id` FOREIGN KEY (`transaction_id`,`transaction_version_id`) REFERENCES `order_transaction` (`id`,`version_id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
