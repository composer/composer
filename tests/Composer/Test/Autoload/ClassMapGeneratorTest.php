<?php

/*
 * This file was copied from the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Autoload;

use Composer\Autoload\ClassMapGenerator;
use Symfony\Component\Finder\Finder;
use Composer\Util\Filesystem;

class ClassMapGeneratorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider getTestCreateMapTests
     */
    public function testCreateMap($directory, $expected)
    {
        $this->assertEqualsNormalized($expected, ClassMapGenerator::createMap($directory));
    }

    public function getTestCreateMapTests()
    {
        $data = array(
            array(__DIR__.'/Fixtures/Namespaced', array(
                'Namespaced\\Bar' => realpath(__DIR__).'/Fixtures/Namespaced/Bar.inc',
                'Namespaced\\Foo' => realpath(__DIR__).'/Fixtures/Namespaced/Foo.php',
                'Namespaced\\Baz' => realpath(__DIR__).'/Fixtures/Namespaced/Baz.php',
            )),
            array(__DIR__.'/Fixtures/beta/NamespaceCollision', array(
                'NamespaceCollision\\A\\B\\Bar' => realpath(__DIR__).'/Fixtures/beta/NamespaceCollision/A/B/Bar.php',
                'NamespaceCollision\\A\\B\\Foo' => realpath(__DIR__).'/Fixtures/beta/NamespaceCollision/A/B/Foo.php',
            )),
            array(__DIR__.'/Fixtures/Pearlike', array(
                'Pearlike_Foo' => realpath(__DIR__).'/Fixtures/Pearlike/Foo.php',
                'Pearlike_Bar' => realpath(__DIR__).'/Fixtures/Pearlike/Bar.php',
                'Pearlike_Baz' => realpath(__DIR__).'/Fixtures/Pearlike/Baz.php',
            )),
            array(__DIR__.'/Fixtures/classmap', array(
                'Foo\\Bar\\A'             => realpath(__DIR__).'/Fixtures/classmap/sameNsMultipleClasses.php',
                'Foo\\Bar\\B'             => realpath(__DIR__).'/Fixtures/classmap/sameNsMultipleClasses.php',
                'Alpha\\A'                => realpath(__DIR__).'/Fixtures/classmap/multipleNs.php',
                'Alpha\\B'                => realpath(__DIR__).'/Fixtures/classmap/multipleNs.php',
                'A'                       => realpath(__DIR__).'/Fixtures/classmap/multipleNs.php',
                'Be\\ta\\A'               => realpath(__DIR__).'/Fixtures/classmap/multipleNs.php',
                'Be\\ta\\B'               => realpath(__DIR__).'/Fixtures/classmap/multipleNs.php',
                'ClassMap\\SomeInterface' => realpath(__DIR__).'/Fixtures/classmap/SomeInterface.php',
                'ClassMap\\SomeParent'    => realpath(__DIR__).'/Fixtures/classmap/SomeParent.php',
                'ClassMap\\SomeClass'     => realpath(__DIR__).'/Fixtures/classmap/SomeClass.php',
                'Foo\\LargeClass'         => realpath(__DIR__).'/Fixtures/classmap/LargeClass.php',
                'Foo\\LargeGap'           => realpath(__DIR__).'/Fixtures/classmap/LargeGap.php',
                'Foo\\MissingSpace'       => realpath(__DIR__).'/Fixtures/classmap/MissingSpace.php',
                'Foo\\StripNoise'         => realpath(__DIR__).'/Fixtures/classmap/StripNoise.php',
                'Foo\\SlashedA'           => realpath(__DIR__).'/Fixtures/classmap/BackslashLineEndingString.php',
                'Foo\\SlashedB'           => realpath(__DIR__).'/Fixtures/classmap/BackslashLineEndingString.php',
                'Unicode\\↑\\↑'              => realpath(__DIR__).'/Fixtures/classmap/Unicode.php',
            )),
            array(__DIR__.'/Fixtures/template', array()),
        );

        if (version_compare(PHP_VERSION, '5.4', '>=')) {
            $data[] = array(__DIR__.'/Fixtures/php5.4', array(
                'TFoo' => __DIR__.'/Fixtures/php5.4/traits.php',
                'CFoo' => __DIR__.'/Fixtures/php5.4/traits.php',
                'Foo\\TBar' => __DIR__.'/Fixtures/php5.4/traits.php',
                'Foo\\IBar' => __DIR__.'/Fixtures/php5.4/traits.php',
                'Foo\\TFooBar' => __DIR__.'/Fixtures/php5.4/traits.php',
                'Foo\\CBar' => __DIR__.'/Fixtures/php5.4/traits.php',
            ));
        }
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.3', '>=')) {
            $data[] = array(__DIR__.'/Fixtures/hhvm3.3', array(
                'FooEnum' => __DIR__.'/Fixtures/hhvm3.3/HackEnum.php',
                'Foo\BarEnum' => __DIR__.'/Fixtures/hhvm3.3/NamespacedHackEnum.php',
                'GenericsClass' => __DIR__.'/Fixtures/hhvm3.3/Generics.php',
            ));
        }

        return $data;
    }

    public function testCreateMapFinderSupport()
    {
        $this->checkIfFinderIsAvailable();

        $finder = new Finder();
        $finder->files()->in(__DIR__ . '/Fixtures/beta/NamespaceCollision');

        $this->assertEqualsNormalized(array(
            'NamespaceCollision\\A\\B\\Bar' => realpath(__DIR__).'/Fixtures/beta/NamespaceCollision/A/B/Bar.php',
            'NamespaceCollision\\A\\B\\Foo' => realpath(__DIR__).'/Fixtures/beta/NamespaceCollision/A/B/Foo.php',
        ), ClassMapGenerator::createMap($finder));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Could not scan for classes inside
     */
    public function testFindClassesThrowsWhenFileDoesNotExist()
    {
        $r = new \ReflectionClass('Composer\\Autoload\\ClassMapGenerator');
        $find = $r->getMethod('findClasses');
        $find->setAccessible(true);

        $find->invoke(null, __DIR__.'/no-file');
    }

    public function testAmbiguousReference()
    {
        $this->checkIfFinderIsAvailable();

        $tempDir = sys_get_temp_dir().'/ComposerTestAmbiguousRefs';
        if (!is_dir($tempDir.'/other')) {
            mkdir($tempDir.'/other', 0777, true);
        }

        $finder = new Finder();
        $finder->files()->in($tempDir);

        $io = $this->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock();

        file_put_contents($tempDir.'/A.php', "<?php\nclass A {}");
        file_put_contents($tempDir.'/other/A.php', "<?php\nclass A {}");

        $a = realpath($tempDir.'/A.php');
        $b = realpath($tempDir.'/other/A.php');
        $msg = '';

        $io->expects($this->once())
            ->method('writeError')
            ->will($this->returnCallback(function ($text) use (&$msg) {
                $msg = $text;
            }));

        $messages = array(
            '<warning>Warning: Ambiguous class resolution, "A" was found in both "'.$a.'" and "'.$b.'", the first will be used.</warning>',
            '<warning>Warning: Ambiguous class resolution, "A" was found in both "'.$b.'" and "'.$a.'", the first will be used.</warning>',
        );

        ClassMapGenerator::createMap($finder, null, $io);

        $this->assertTrue(in_array($msg, $messages, true), $msg.' not found in expected messages ('.var_export($messages, true).')');

        $fs = new Filesystem();
        $fs->removeDirectory($tempDir);
    }

    /**
     * If one file has a class or interface defined more than once,
     * an ambiguous reference warning should not be produced
     */
    public function testUnambiguousReference()
    {
        $tempDir = sys_get_temp_dir().'/ComposerTestUnambiguousRefs';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        file_put_contents($tempDir.'/A.php', "<?php\nclass A {}");

        file_put_contents(
            $tempDir.'/B.php',
            "<?php
                if (true) {
                    interface B {}
                } else {
                    interface B extends Iterator {}
                }
            "
        );

        foreach (array('test', 'fixture', 'example') as $keyword) {
            if (!is_dir($tempDir.'/'.$keyword)) {
                mkdir($tempDir.'/'.$keyword, 0777, true);
            }
            file_put_contents($tempDir.'/'.$keyword.'/A.php', "<?php\nclass A {}");
        }

        $io = $this->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock();

        $io->expects($this->never())
            ->method('write');

        ClassMapGenerator::createMap($tempDir, null, $io);

        $fs = new Filesystem();
        $fs->removeDirectory($tempDir);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Could not scan for classes inside
     */
    public function testCreateMapThrowsWhenDirectoryDoesNotExist()
    {
        ClassMapGenerator::createMap(__DIR__.'/no-file.no-foler');
    }

    protected function assertEqualsNormalized($expected, $actual, $message = null)
    {
        foreach ($expected as $ns => $path) {
            $expected[$ns] = strtr($path, '\\', '/');
        }
        foreach ($actual as $ns => $path) {
            $actual[$ns] = strtr($path, '\\', '/');
        }
        $this->assertEquals($expected, $actual, $message);
    }

    private function checkIfFinderIsAvailable()
    {
        if (!class_exists('Symfony\\Component\\Finder\\Finder')) {
            $this->markTestSkipped('Finder component is not available');
        }
    }
}
