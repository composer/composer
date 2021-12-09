<?php

$bestDirFound = null;
$dirs = glob(__DIR__.'/../vendor/bin/.phpunit/phpunit-*');
natsort($dirs);

foreach (array_reverse($dirs) as $dir) {
    $bestDirFound = $dir;
    if (PHP_VERSION_ID >= 80000 && false !== strpos($dir, 'phpunit-9')) {
        break;
    }
    if (PHP_VERSION_ID < 80000 && false !== strpos($dir, 'phpunit-8')) {
        break;
    }
}

if (null === $bestDirFound) {
    echo 'Run "composer test" to initialize PHPUnit sources before running PHPStan'.PHP_EOL;
    exit(1);
}

include $bestDirFound.'/vendor/autoload.php';
