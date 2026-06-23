<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow;

use Crehler\PaymentBundle\PaymentPluginBootstrap;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\{ActivateContext, DeactivateContext, InstallContext, UninstallContext, UpdateContext};
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * PayNow Payment Plugin for Shopware 6.
 *
 * Extends Shopware's Plugin (never a bundle type in the class signature) so it stays
 * instantiable before crehler/payment-bundle is composer-installed; executeComposerCommands()
 * then pulls the bundle in. All shared lifecycle is delegated to PaymentPluginBootstrap
 * from method bodies. See PaymentPluginBootstrap for the rationale.
 *
 * Payment methods are defined in Infrastructure/Util/Install/
 */
class CrehlerPayNow extends Plugin
{
    /**
     * @var bool
     */
    public const CREHLER_PAYMENT_PLUGIN = true;

    public function executeComposerCommands(): bool
    {
        return true;
    }

    public function getAdditionalBundles(AdditionalBundleParameters $parameters): array
    {
        return PaymentPluginBootstrap::additionalBundles();
    }

    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        parent::configureRoutes($routes, $environment);

        PaymentPluginBootstrap::configureRoutes($routes, $environment, $this->isActive());
    }

    public function install(InstallContext $installContext): void
    {
        PaymentPluginBootstrap::install($installContext, $this->container, static::class);
    }

    public function update(UpdateContext $updateContext): void
    {
        PaymentPluginBootstrap::update($updateContext, $this->container, static::class);
    }

    public function activate(ActivateContext $activateContext): void
    {
        PaymentPluginBootstrap::activate($activateContext, $this->container, static::class);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        PaymentPluginBootstrap::deactivate($deactivateContext, $this->container, static::class);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        PaymentPluginBootstrap::uninstall($uninstallContext, $this->container, static::class);
    }
}
