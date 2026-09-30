<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Storefront;

use Crehler\PaymentBundle\Application\Port\Driven\CardFormTemplateProviderPort;
use Crehler\PayNowPayment\Handler\CardHandler;

use function in_array;

/**
 * Points the bundle at PayNow's own card form template.
 *
 * Namespaced to this plugin on purpose: the previous mechanism relied on a Twig block in
 * a template whose path collided with the bundle's and the Storefront's, which is exactly
 * why the section rendered empty (see CardFormTemplateProviderPort).
 */
final class PayNowCardFormTemplateProvider implements CardFormTemplateProviderPort
{
    /**
     * @var mixed[]
     */
    private const SUPPORTED_HANDLERS = [
        CardHandler::class,
    ];

    public function supports(string $handlerIdentifier): bool
    {
        return in_array($handlerIdentifier, self::SUPPORTED_HANDLERS, true);
    }

    public function getTemplate(): string
    {
        return '@CrehlerPayNowPayment/storefront/component/payment/paynow-card-form.html.twig';
    }
}
