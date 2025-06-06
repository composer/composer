<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Composer\Util\Platform;

error_reporting(E_ALL);

if (function_exists('date_default_timezone_set') && function_exists('date_default_timezone_get')) {
    date_default_timezone_set(@date_default_timezone_get());
}

require __DIR__.'/../src/bootstrap.php';
// ensure we always use the latest InstalledVersions.php even if an older composer ran the install, but we need
// to have it included from vendor dir and not from src/ otherwise some gated check in the code will not work
copy(__DIR__.'/../src/Composer/InstalledVersions.php', __DIR__.'/../vendor/composer/InstalledVersions.php');
require __DIR__.'/../vendor/composer/InstalledVersions.php';

Platform::putEnv('COMPOSER_TESTS_ARE_RUNNING', '1');

// ensure Windows color support detection does not attempt to use colors
// as this is dependent on env vars and not actual stream capabilities, see
// https://github.com/composer/composer/issues/11598
Platform::putEnv('NO_COLOR', '1');

// symfony/phpunit-bridge sets some default env vars which we do not need polluting the test env
Platform::clearEnv('COMPOSER');
Platform::clearEnv('COMPOSER_VENDOR_DIR');
Platform::clearEnv('COMPOSER_BIN_DIR');

/**
 * The stream wrapper checks use a static property to store the stream wrapper names. If it is accessed before
 * the stream wrapper is registered, and a later test relies on that stream wrapper, the static property will be
 * outdated and the test will fail.
 *
 * @see \Composer\Util\Filesystem::isStreamWrapperPath()
 */
stream_wrapper_register('composertestsstreamwrapper', stdclass::class);
