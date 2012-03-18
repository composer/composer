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

class FactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider dataAddPackagistRepository
     */
    public function testAddPackagistRepository($expected, $config)
    {
        $factory = new Factory();

        $ref = new \ReflectionMethod($factory, 'addPackagistRepository');
        $ref->setAccessible(true);

        $this->assertEquals($expected, $ref->invoke($factory, $config));
    }

    public function dataAddPackagistRepository()
    {
        $f = function() {
            $repositories = func_get_args();
            return array('repositories' => $repositories);
        };

        $data = array();
        $data[] = array(
            $f(array('type' => 'composer', 'url' => 'http://packagist.org')),
            $f()
        );

        $data[] = array(
            $f(array('packagist' => false)),
            $f(array('packagist' => false))
        );

        $data[] = array(
            $f(
                array('type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'),
                array('type' => 'composer', 'url' => 'http://packagist.org'),
                array('type' => 'pear', 'url' => 'http://pear.composer.org')
            ),
            $f(
                array('type' => 'vcs', 'url' => 'git://github.com/composer/composer.git'),
                array('packagist' => true),
                array('type' => 'pear', 'url' => 'http://pear.composer.org')
            )
        );

        return $data;
    }
}
