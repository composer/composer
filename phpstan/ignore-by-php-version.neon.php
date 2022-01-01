<?php declare(strict_types = 1);

use PHPStan\DependencyInjection\NeonAdapter;

$adapter = new NeonAdapter();

// more inspiration at https://github.com/phpstan/phpstan-src/blob/master/build/ignore-by-php-version.neon.php
$config = [];
if (PHP_VERSION_ID >= 80000) {
    $config = array_merge_recursive($config, $adapter->load(__DIR__ . '/baseline-8.1.neon'));
}
$config['parameters']['phpVersion'] = PHP_VERSION_ID;

return $config;
