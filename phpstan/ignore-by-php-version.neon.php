<?php declare(strict_types = 1);

// more inspiration at https://github.com/phpstan/phpstan-src/blob/master/build/ignore-by-php-version.neon.php
$includes = [];
if (PHP_VERSION_ID >= 80000) {
    $includes[] = __DIR__ . '/baseline-8.3.neon';
}

$config['includes'] = $includes;
$config['parameters']['phpVersion'] = PHP_VERSION_ID;

return $config;
