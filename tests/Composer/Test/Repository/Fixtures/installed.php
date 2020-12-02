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
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'aliases' => array(
                '1.10.x-dev',
            ),
            'reference' => 'sourceref-by-default',
        ),
        'a/provider' => array(
            'pretty_version' => '1.1',
            'version' => '1.1.0.0',
            'aliases' => array(),
            'reference' => 'distref-as-no-source',
        ),
        'a/provider2' => array(
            'pretty_version' => '1.2',
            'version' => '1.2.0.0',
            'aliases' => array(
              '1.4',
            ),
            'reference' => 'distref-as-installed-from-dist',
        ),
        'b/replacer' => array(
            'pretty_version' => '2.2',
            'version' => '2.2.0.0',
            'aliases' => array(),
            'reference' => null,
        ),
        'c/c' => array(
            'pretty_version' => '3.0',
            'version' => '3.0.0.0',
            'aliases' => array(),
            'reference' => null,
        ),
        'foo/impl' => array(
            'provided' => array(
                '^1.1',
                '1.2',
                '1.4',
                '2.0',
            ),
        ),
        'foo/impl2' => array(
            'provided' => array(
                '2.0',
            ),
            'replaced' => array(
                '2.2',
            ),
        ),
        'foo/replaced' => array(
            'replaced' => array(
                '^3.0',
            ),
        ),
    ),
);
