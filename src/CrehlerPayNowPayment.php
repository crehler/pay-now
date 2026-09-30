<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNowPayment;

use Crehler\PaymentBundle\PaymentPluginBootstrap;
use Crehler\PayNowPayment\Infrastructure\Lifecycle\LegacyInstallationMigrator;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\{ActivateContext, DeactivateContext, InstallContext, UninstallContext, UpdateContext};
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function class_exists;

/**
 * PayNow Payment Plugin for Shopware 6.
 *
 * Extends Shopware's Plugin (never a bundle type in the class signature) so it stays
 * instantiable before crehler/payment-bundle is composer-installed; executeComposerCommands()
 * then pulls the bundle in. All shared lifecycle is delegated to PaymentPluginBootstrap
 * from method bodies. See PaymentPluginBootstrap for the rationale.
 *
 * The technical name CrehlerPayNowPayment is the one of the legacy 4.x line in the Shopware
 * Store, so 6.x reaches those shops as an update of it — see LegacyInstallationMigrator.
 *
 * Payment methods are defined in Infrastructure/Util/Install/
 */
class CrehlerPayNowPayment extends Plugin
{
    /**
     * @var bool
     */
    public const CREHLER_PAYMENT_PLUGIN = true;

    public function executeComposerCommands(): bool
    {
        return true;
    }

    /**
     * Degraded boot while crehler/payment-bundle is missing. Updating from 4.x (active, no
     * bundle) takes two requests: one unpacks the new files, the next runs update() — and
     * that next request boots the kernel with THIS class already. Its services, bundles and
     * routes all reference bundle classes, so registering any of them would take the whole
     * shop down before update() gets the chance to run Composer. The plugin therefore
     * contributes nothing until the bundle is there; the container is rebuilt after update().
     */
    public function build(ContainerBuilder $container): void
    {
        if (!self::dependenciesInstalled()) {
            return;
        }

        parent::build($container);
    }

    public function getAdditionalBundles(AdditionalBundleParameters $parameters): array
    {
        return self::dependenciesInstalled() ? PaymentPluginBootstrap::additionalBundles() : [];
    }

    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        parent::configureRoutes($routes, $environment);

        if (self::dependenciesInstalled()) {
            PaymentPluginBootstrap::configureRoutes($routes, $environment, $this->isActive());
        }
    }

    public function install(InstallContext $installContext): void
    {
        self::assertDependenciesInstalled();

        $this->legacyInstallationMigrator()->copyRenamedConfiguration();

        PaymentPluginBootstrap::install($installContext, $this->container, static::class);
    }

    public function update(UpdateContext $updateContext): void
    {
        self::assertDependenciesInstalled();

        if (LegacyInstallationMigrator::isLegacyVersion($updateContext->getCurrentPluginVersion())) {
            $this->legacyInstallationMigrator()->migrateFromLegacyLine();
        }

        PaymentPluginBootstrap::update($updateContext, $this->container, static::class);
    }

    public function activate(ActivateContext $activateContext): void
    {
        self::assertDependenciesInstalled();

        PaymentPluginBootstrap::activate($activateContext, $this->container, static::class);
    }

    /**
     * Deactivate and uninstall stay possible without the bundle (e.g. 4.x files replaced but
     * Composer could not run) — they are the way out of that state, not something to block.
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        if (!self::dependenciesInstalled()) {
            return;
        }

        PaymentPluginBootstrap::deactivate($deactivateContext, $this->container, static::class);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if (!self::dependenciesInstalled()) {
            return;
        }

        PaymentPluginBootstrap::uninstall($uninstallContext, $this->container, static::class);
    }

    /**
     * Shopware skips executeComposerCommands() without a word when the shop runs with
     * shopware.deployment.cluster_setup, and the bundle is then simply missing — fail
     * with an instruction instead of a class-not-found fatal error.
     */
    private static function assertDependenciesInstalled(): void
    {
        if (!self::dependenciesInstalled()) {
            throw new RuntimeException('CrehlerPayNowPayment: the dependency crehler/payment-bundle is not installed. Shopware could not run Composer for this plugin (e.g. cluster setup, no write access to vendor/ or no access to Packagist). Install it with: composer require crehler/pay-now');
        }
    }

    private static function dependenciesInstalled(): bool
    {
        return class_exists(PaymentPluginBootstrap::class);
    }

    private function legacyInstallationMigrator(): LegacyInstallationMigrator
    {
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        return new LegacyInstallationMigrator($connection);
    }
}
