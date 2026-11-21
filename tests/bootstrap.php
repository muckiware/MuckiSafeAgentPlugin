<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiSafeAgent
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
/**
 * MuckiSafeAgentPlugin test bootstrap.
 *
 * Two execution modes, selected by the requested PHPUnit testsuite:
 *
 *  1. Unit mode (`phpunit --testsuite Unit`): no Shopware kernel and no
 *     database. The suite only needs an autoloader that can resolve the
 *     plugin sources and the mocked Shopware classes: either a plugin-local
 *     vendor directory (composer install inside this plugin) or the vendor
 *     directory of the surrounding Shopware project (this plugin usually
 *     lives in custom/plugins/).
 *
 *  2. Integration mode (default / --testsuite Integration): boots the
 *     Shopware test kernel through TestBootstrapper with MuckiFacilityPlugin
 *     and this plugin as active plugins and the database from
 *     TEST_DATABASE_URL. This mode requires an installed Shopware instance —
 *     see tests/Integration/run-shopware.sh, which provisions it.
 *
 * Environment contract (kept identical between run-shopware.sh and the CI
 * workflow php-tests.yml):
 *
 *   TEST_DATABASE_URL   database the integration kernel uses, e.g.
 *                       mysql://root:***@127.0.0.1:3384/muwa_8_4_v6_7_14_1
 *                       (falls back to DATABASE_URL from the project .env
 *                       when unset)
 *   MUWA_SHOPWARE_ROOT  Shopware project root, auto-detected when unset
 */

use Shopware\Core\TestBootstrapper;
use Symfony\Component\Dotenv\Dotenv;

function muwaRequestedTestsuites(): array
{
    $argv = $_SERVER['argv'] ?? [];

    foreach ($argv as $index => $argument) {
        if ($argument === '--testsuite' && isset($argv[$index + 1])) {
            return array_map('trim', explode(',', (string) $argv[$index + 1]));
        }
    }

    return [];
}

function muwaUnitModeOnly(): bool
{
    $suites = muwaRequestedTestsuites();

    return $suites !== [] && !in_array('Integration', $suites, true);
}

/**
 * Shopware project root: MUWA_SHOPWARE_ROOT when set, otherwise detected by
 * walking up from this plugin directory until bin/console appears.
 */
function muwaShopwareRoot(): string
{
    $configured = $_SERVER['MUWA_SHOPWARE_ROOT'] ?? $_ENV['MUWA_SHOPWARE_ROOT'] ?? null;
    if (is_string($configured) && $configured !== '' && is_dir($configured)) {
        return rtrim($configured, '/');
    }

    $dir = dirname(__DIR__);
    while (true) {
        if (file_exists($dir . '/bin/console')) {
            return $dir;
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            throw new RuntimeException(
                'Could not locate the Shopware project root from ' . dirname(__DIR__) . '. '
                . 'Run the tests inside a Shopware installation or set MUWA_SHOPWARE_ROOT.'
            );
        }

        $dir = $parent;
    }
}

$loader = null;

if (muwaUnitModeOnly()) {
    /*
     * Unit mode: autoloading only, no kernel, no database.
     */
    $pluginVendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';

    if (file_exists($pluginVendorAutoload)) {
        /** @var Composer\Autoload\ClassLoader $pluginLoader */
        $pluginLoader = require $pluginVendorAutoload;
        $pluginLoader->addPsr4('MuckiSafeAgentPlugin\\tests\\', __DIR__);
    }

    try {
        $shopwareRoot = muwaShopwareRoot();
        /** @var Composer\Autoload\ClassLoader $rootLoader */
        $rootLoader = require $shopwareRoot . '/vendor/autoload.php';
        // Static plugins are not part of the project autoloader.
        $rootLoader->addPsr4('MuckiSafeAgentPlugin\\', dirname(__DIR__) . '/src/');
        $rootLoader->addPsr4('MuckiSafeAgentPlugin\\tests\\', __DIR__);
    } catch (RuntimeException $exception) {
        if (!file_exists($pluginVendorAutoload)) {
            throw new RuntimeException(
                'Unit mode needs either a plugin-local vendor directory (composer install inside '
                . 'the plugin) or a surrounding Shopware project. Neither was found.',
                0,
                $exception
            );
        }
    }

    return;
}

/*
 * Integration mode: boot the Shopware test kernel.
 */
$testProjectDir = muwaShopwareRoot();

if (file_exists($testProjectDir . '/.env')) {
    (new Dotenv())->usePutenv()->load($testProjectDir . '/.env');
}

$testDatabaseUrl = $_SERVER['TEST_DATABASE_URL'] ?? $_ENV['TEST_DATABASE_URL'] ?? getenv('TEST_DATABASE_URL');
if (!is_string($testDatabaseUrl) || $testDatabaseUrl === '') {
    $testDatabaseUrl = getenv('DATABASE_URL') ?: null;
}

echo 'MuckiSafeAgentPlugin test bootstrap: project dir ' . $testProjectDir
    . ', database ' . (is_string($testDatabaseUrl) ? preg_replace('#//[^@]+@#', '//***@', $testDatabaseUrl) : '(DATABASE_URL)')
    . PHP_EOL;

/** @var Shopware\Core\TestBootstrapper $bootstrapper */
$bootstrapper = (new TestBootstrapper())
    ->addCallingPlugin()
    ->addActivePlugins('MuckiFacilityPlugin')
    ->setDatabaseUrl(is_string($testDatabaseUrl) && $testDatabaseUrl !== '' ? $testDatabaseUrl : null)
    ->bootstrap();

/** @var Composer\Autoload\ClassLoader $loader */
$loader = $bootstrapper->getClassLoader();
$loader->addPsr4('MuckiSafeAgentPlugin\\tests\\', __DIR__);
