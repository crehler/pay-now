<?php

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

declare(strict_types=1);

// Autoload only. tests/TestBootstrap.php boots a whole Shopware and installs the plugin,
// which is what an integration test needs and what makes a unit test impossible to run
// without a shop and a database.
$autoloadPath = null;
foreach ([__DIR__ . '/../../../../vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $candidate) {
    if (file_exists($candidate)) {
        $autoloadPath = $candidate;

        break;
    }
}

if ($autoloadPath === null) {
    throw new \RuntimeException('Vendor autoload not found — run composer install.');
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $autoloadPath;
$loader->addPsr4('Crehler\\PayNowPayment\\Tests\\', __DIR__);
// The plugin may not be composer-installed in the shop root — register src for unit tests.
$loader->addPsr4('Crehler\\PayNowPayment\\', __DIR__ . '/../src');
