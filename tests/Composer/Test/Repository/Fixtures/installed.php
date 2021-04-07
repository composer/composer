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
            'aliases' => array(
                '1.10.x-dev',
            ),
            'reference' => 'sourceref-by-default',
            'dev-requirement' => false,
        ),
        'a/provider' => array(
            'pretty_version' => '1.1',
            'version' => '1.1.0.0',
            'aliases' => array(),
            'reference' => 'distref-as-no-source',
            'dev-requirement' => false,
        ),
        'a/provider2' => array(
            'pretty_version' => '1.2',
            'version' => '1.2.0.0',
            'aliases' => array(
              '1.4',
            ),
            'reference' => 'distref-as-installed-from-dist',
            'dev-requirement' => false,
        ),
        'b/replacer' => array(
            'pretty_version' => '2.2',
            'version' => '2.2.0.0',
            'aliases' => array(),
            'reference' => null,
            'dev-requirement' => false,
        ),
        'c/c' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'aliases' => array(),
            'reference' => null,
            'dev-requirement' => true,
        ),
        'foo/impl' => array(
            'dev-requirement' => false,
            'provided' => array(
                '^1.1',
                '1.2',
                '1.4',
                '2.0',
            ),
        ),
        'foo/impl2' => array(
            'dev-requirement' => false,
            'provided' => array(
                '2.0',
            ),
            'replaced' => array(
                '2.2',
            ),
        ),
        'foo/replaced' => array(
            'dev-requirement' => false,
            'replaced' => array(
                '^3.0',
            ),
        ),
    ),
);
