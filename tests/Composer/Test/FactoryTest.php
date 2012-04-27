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
    protected $defaultComposerRepositories;

    protected function setUp()
    {
        $this->defaultComposerRepositories = Factory::$defaultComposerRepositories;
    }

    protected function tearDown()
    {
        Factory::$defaultComposerRepositories = $this->defaultComposerRepositories;
        unset($this->defaultComposerRepositories);
    }

    /**
     * @dataProvider dataAddPackagistRepository
     */
    public function testAddPackagistRepository($expected, $config, $defaults = null)
    {
        if (null !== $defaults) {
            Factory::$defaultComposerRepositories = $defaults;
        }

        $factory = new Factory();

        $ref = new \ReflectionMethod($factory, 'addComposerRepositories');
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

        $multirepo = array(
            'example.com' => 'http://example.com',
            'packagist' => 'http://packagist.org',
        );

        $data[] = array(
            $f(
                array('type' => 'composer', 'url' => 'http://example.com'),
                array('type' => 'composer', 'url' => 'http://packagist.org')
            ),
            $f(),
            $multirepo,
        );

        $data[] = array(
            $f(
                array('type' => 'composer', 'url' => 'http://packagist.org'),
                array('type' => 'composer', 'url' => 'http://example.com')
            ),
            $f(array('packagist' => true)),
            $multirepo,
        );

        return $data;
    }
}
