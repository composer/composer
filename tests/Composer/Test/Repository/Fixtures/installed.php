<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return array(
    'root' => array(
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'type' => 'library',
        // @phpstan-ignore-next-line
        'install_path' => $dir . '/./',
        'aliases' => array(
            '1.10.x-dev',
        ),
        'reference' => 'sourceref-by-default',
        'name' => '__root__',
        'dev' => true,
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'type' => 'library',
            // @phpstan-ignore-next-line
            'install_path' => $dir . '/./',
            'aliases' => array(
                '1.10.x-dev',
            ),
            'reference' => 'sourceref-by-default',
            'dev_requirement' => false,
        ),
        'a/provider' => array(
            'pretty_version' => '1.1',
            'version' => '1.1.0.0',
            'type' => 'library',
            // @phpstan-ignore-next-line
            'install_path' => $dir . '/vendor/a/provider',
            'aliases' => array(),
            'reference' => 'distref-as-no-source',
            'dev_requirement' => false,
        ),
        'a/provider2' => array(
            'pretty_version' => '1.2',
            'version' => '1.2.0.0',
            'type' => 'library',
            // @phpstan-ignore-next-line
            'install_path' => $dir . '/vendor/a/provider2',
            'aliases' => array(
              '1.4',
            ),
            'reference' => 'distref-as-installed-from-dist',
            'dev_requirement' => false,
        ),
        'b/replacer' => array(
            'pretty_version' => '2.2',
            'version' => '2.2.0.0',
            'type' => 'library',
            // @phpstan-ignore-next-line
            'install_path' => $dir . '/vendor/b/replacer',
            'aliases' => array(),
            'reference' => null,
            'dev_requirement' => false,
        ),
        'c/c' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'type' => 'library',
            'install_path' => '/foo/bar/vendor/c/c',
            'aliases' => array(),
            'reference' => null,
            'dev_requirement' => true,
        ),
        'foo/impl' => array(
            'dev_requirement' => false,
            'provided' => array(
                '^1.1',
                '1.2',
                '1.4',
                '2.0',
            ),
        ),
        'foo/impl2' => array(
            'dev_requirement' => false,
            'provided' => array(
                '2.0',
            ),
            'replaced' => array(
                '2.2',
            ),
        ),
        'foo/replaced' => array(
            'dev_requirement' => false,
            'replaced' => array(
                '^3.0',
            ),
        ),
        'meta/package' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'type' => 'metapackage',
            'install_path' => null,
            'aliases' => array(),
            'reference' => null,
            'dev_requirement' => false,
        )
    ),
);
