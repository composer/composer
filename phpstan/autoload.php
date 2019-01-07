<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('apcu')) {
    require_once __DIR__ . '/stubs/apcu.php';
}

if (!extension_loaded('rar')) {
    require_once __DIR__ . '/stubs/rar.php';
}

require_once __DIR__ . '/../src/bootstrap.php';
