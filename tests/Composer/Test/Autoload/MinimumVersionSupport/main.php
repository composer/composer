<?php

include 'vendor/autoload.php';

Test\Foo::test();

if (Composer\InstalledVersions::isInstalled('root/pkg')) {
    echo 'isInstalled: OK'.PHP_EOL;
}
