<?php

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
     * @param bool $prependSeparator Whether to call ->loadClass() with a class name with preceding
     *                               namespace separator, as it happens in PHP 5.3.0 - 5.3.2. See https://bugs.php.net/50731
     */
    public function testLoadClass($class, $prependSeparator = FALSE)
    {
        $loader = new ClassLoader();
        $loader->add('Namespaced\\', __DIR__ . '/Fixtures');
        $loader->add('Pearlike_', __DIR__ . '/Fixtures');
        $loader->addPsr4('ShinyVendor\\ShinyPackage\\', __DIR__ . '/Fixtures');

        if ($prependSeparator) {
            $prepend = '\\';
            $message = "->loadClass() loads '$class'.";
        } else {
            $prepend = '';
            $message = "->loadClass() loads '\\$class', as required in PHP 5.3.0 - 5.3.2.";
        }

        $loader->loadClass($prepend . $class);
        $this->assertTrue(class_exists($class, false), $message);
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
            // "Bar" would not work here, since it is defined in a ".inc" file,
            // instead of a ".php" file. So, use "Baz" instead.
            array('Namespaced\\Baz', true),
            array('Pearlike_Bar', true),
            array('ShinyVendor\\ShinyPackage\\SubNamespace\\Bar', true),
        );
    }
}
