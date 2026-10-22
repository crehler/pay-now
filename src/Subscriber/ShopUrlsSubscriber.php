<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment\Subscriber;

use Crehler\PayNowPayment\Infrastructure\ShopUrls\ShopUrlsConfigurator;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\Event\SystemConfigMultipleChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function array_key_exists;

/**
 * After the PayNow configuration is saved, put the plugin's notification and return
 * address into PayNow — what PayNow's own plugins do on every configuration save.
 *
 * Listens to the batch event (one per save, with the saved scope) rather than the per-key
 * one, so a save that touches several keys sends one request per environment, not one per
 * key. Only a save carrying credentials or the sandbox switch triggers it.
 *
 * Never throws: a failed call must not turn a successful configuration save into an
 * error. The failure is logged, and the "PayNow panel addresses" card offers a retry and
 * the addresses to copy by hand.
 */
final readonly class ShopUrlsSubscriber implements EventSubscriberInterface
{
    private const TRIGGER_KEYS = [
        'CrehlerPayNowPayment.config.apiKey',
        'CrehlerPayNowPayment.config.signatureKeyLabel',
        'CrehlerPayNowPayment.config.sandboxApiKey',
        'CrehlerPayNowPayment.config.sandboxSignatureKeyLabel',
        'CrehlerPayNowPayment.config.enableSandbox',
    ];

    public function __construct(
        private ShopUrlsConfigurator $configurator,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigMultipleChangedEvent::class => 'onConfigurationSaved'];
    }

    public function onConfigurationSaved(SystemConfigMultipleChangedEvent $event): void
    {
        if (!self::touchesCredentials($event->getConfig())) {
            return;
        }

        try {
            $this->configurator->configure($event->getSalesChannelId());
        } catch (Throwable $e) {
            $this->logger->warning('Setting the PayNow shop URLs after a configuration save failed', [
                'salesChannelId' => $event->getSalesChannelId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function touchesCredentials(array $config): bool
    {
        foreach (self::TRIGGER_KEYS as $key) {
            if (array_key_exists($key, $config)) {
                return true;
            }
        }

        return false;
    }
}
