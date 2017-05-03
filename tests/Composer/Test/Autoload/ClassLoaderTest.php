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

namespace Composer\Test\Autoload;

use Composer\Autoload\ClassLoader;

/**
 * Tests the Composer\Autoload\ClassLoader class.
 */
class ClassLoaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Tests regular PSR-0 and PSR-4 class loading.
     *
     * @dataProvider getLoadClassTests
     *
     * @param string $class The fully-qualified class name to test, without preceding namespace separator.
     */
    public function testLoadClass($class)
    {
        $loader = new ClassLoader();
        $loader->add('Namespaced\\', __DIR__ . '/Fixtures');
        $loader->add('Pearlike_', __DIR__ . '/Fixtures');
        $loader->addPsr4('ShinyVendor\\ShinyPackage\\', __DIR__ . '/Fixtures');
        $loader->loadClass($class);
        $this->assertTrue(class_exists($class, false), "->loadClass() loads '$class'");
    }

    /**
     * Provides arguments for ->testLoadClass().
     *
     * @return array Array of parameter sets to test with.
     */
    public function getLoadClassTests()
    {
        return array(
            array('Namespaced\\Foo'),
            array('Pearlike_Foo'),
            array('ShinyVendor\\ShinyPackage\\SubNamespace\\Foo'),
        );
    }

    /**
     * getPrefixes method should return empty array if ClassLoader does not have any psr-0 configuration
     */
    public function testGetPrefixesWithNoPSR0Configuration()
    {
        $loader = new ClassLoader();
        $this->assertEmpty($loader->getPrefixes());
    }
}
