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

use Composer\InstalledVersions;
use Composer\Util\Platform;

error_reporting(E_ALL);

if (function_exists('date_default_timezone_set') && function_exists('date_default_timezone_get')) {
    date_default_timezone_set(@date_default_timezone_get());
}

require __DIR__.'/../src/bootstrap.php';

if (!class_exists(InstalledVersions::class, false)) {
    require __DIR__.'/../src/Composer/InstalledVersions.php';
}

require __DIR__.'/Composer/Test/TestCase.php';

Platform::putEnv('COMPOSER_TESTS_ARE_RUNNING', '1');

// symfony/phpunit-bridge sets some default env vars which we do not need polluting the test env
Platform::clearEnv('COMPOSER');
Platform::clearEnv('COMPOSER_VENDOR_DIR');
Platform::clearEnv('COMPOSER_BIN_DIR');
Platform::clearEnv('COMPOSER_TMP_DIR');
