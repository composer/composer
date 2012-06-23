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

namespace Composer\Test;

use Composer\Factory;
use Composer\Config;

class FactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider dataAddPackagistRepository
     */
    public function testAddPackagistRepository($expected, $composerConfig, $defaults = null)
    {
        $factory = new Factory();
        $config = new Config();
        if ($defaults) {
            $config->merge(array('repositories' => $defaults));
        }

        $ref = new \ReflectionMethod($factory, 'addDefaultRepositories');
        $ref->setAccessible(true);

        $this->assertEquals($expected, $ref->invoke($factory, $config, $composerConfig));
    }

    public function dataAddPackagistRepository()
    {
        $repos = function() {
            $repositories = func_get_args();

            return array('repositories' => $repositories);
        };

        $data = array();
        $data[] = array(
            $repos(array('type' => 'composer', 'url' => 'http://packagist.org')),
            $repos()
        );

        $data[] = array(
            $repos(array('packagist' => false)),
            $repos(array('packagist' => false))
        );

        $data[] = array(
            $repos(
                array('type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'),
                array('type' => 'composer', 'url' => 'http://packagist.org'),
                array('type' => 'pear', 'url' => 'http://pear.composer.org')
            ),
            $repos(
                array('type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'),
                array('packagist' => true),
                array('type' => 'pear', 'url' => 'http://pear.composer.org')
            )
        );

        $multirepo = array(
            'example.com' => 'http://example.com',
            'packagist' => 'http://packagist.org',
        );

        $data[] = array(
            $repos(
                array('type' => 'composer', 'url' => 'http://example.com'),
                array('type' => 'composer', 'url' => 'http://packagist.org')
            ),
            $repos(),
            $multirepo,
        );

        $data[] = array(
            $repos(
                array('type' => 'composer', 'url' => 'http://packagist.org'),
                array('type' => 'composer', 'url' => 'http://example.com')
            ),
            $repos(array('packagist' => true)),
            $multirepo,
        );

        return $data;
    }
}
