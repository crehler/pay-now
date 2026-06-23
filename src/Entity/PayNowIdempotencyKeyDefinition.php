<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Entity;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\{EntityDefinition, FieldCollection};
use Shopware\Core\Framework\DataAbstractionLayer\Field\{FkField, IdField, ManyToOneAssociationField, ReferenceVersionField, StringField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{ApiAware, Inherited, PrimaryKey, Required};

class PayNowIdempotencyKeyDefinition extends EntityDefinition
{
    /**
     * @var string
     */
    public const ENTITY_NAME = 'paynow_idempotency_key';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('transaction_id', 'transactionId', OrderTransactionDefinition::class))->addFlags(new Required()),
            new ManyToOneAssociationField('transaction', 'transaction_id', OrderTransactionDefinition::class, 'id', false),
            (new ReferenceVersionField(OrderTransactionDefinition::class, 'transaction_version_id'))->addFlags(new ApiAware(), new Inherited(), new Required()),
            (new StringField('idempotency_key', 'idempotencyKey'))->addFlags(new Required()),
        ]);
    }
}
