<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * This file is copied from the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 */

namespace Composer\Test\Autoload;

use Composer\Autoload\ClassMapGenerator;
use Composer\Test\TestCase;
use Symfony\Component\Finder\Finder;
use Composer\Util\Filesystem;

class ClassMapGeneratorTest extends TestCase
{
    /**
     * @dataProvider getTestCreateMapTests
     * @param string $directory
     * @param array<string, string> $expected
     */
    public function testCreateMap(string $directory, array $expected): void
    {
        $this->assertEqualsNormalized($expected, ClassMapGenerator::createMap($directory));
    }

    /**
     * @return array<array<string|array<string>>>
     */
    public function getTestCreateMapTests(): array
    {
        $classmap = array(
            'Foo\\Bar\\A' => realpath(__DIR__) . '/Fixtures/classmap/sameNsMultipleClasses.php',
            'Foo\\Bar\\B' => realpath(__DIR__) . '/Fixtures/classmap/sameNsMultipleClasses.php',
            'Alpha\\A' => realpath(__DIR__) . '/Fixtures/classmap/multipleNs.php',
            'Alpha\\B' => realpath(__DIR__) . '/Fixtures/classmap/multipleNs.php',
            'A' => realpath(__DIR__) . '/Fixtures/classmap/multipleNs.php',
            'Be\\ta\\A' => realpath(__DIR__) . '/Fixtures/classmap/multipleNs.php',
            'Be\\ta\\B' => realpath(__DIR__) . '/Fixtures/classmap/multipleNs.php',
            'ClassMap\\SomeInterface' => realpath(__DIR__) . '/Fixtures/classmap/SomeInterface.php',
            'ClassMap\\SomeParent' => realpath(__DIR__) . '/Fixtures/classmap/SomeParent.php',
            'ClassMap\\SomeClass' => realpath(__DIR__) . '/Fixtures/classmap/SomeClass.php',
            'ClassMap\\LongString' => realpath(__DIR__) . '/Fixtures/classmap/LongString.php',
            'Foo\\LargeClass' => realpath(__DIR__) . '/Fixtures/classmap/LargeClass.php',
            'Foo\\LargeGap' => realpath(__DIR__) . '/Fixtures/classmap/LargeGap.php',
            'Foo\\MissingSpace' => realpath(__DIR__) . '/Fixtures/classmap/MissingSpace.php',
            'Foo\\StripNoise' => realpath(__DIR__) . '/Fixtures/classmap/StripNoise.php',
            'Foo\\First' => realpath(__DIR__) . '/Fixtures/classmap/StripNoise.php',
            'Foo\\Second' => realpath(__DIR__) . '/Fixtures/classmap/StripNoise.php',
            'Foo\\Third' => realpath(__DIR__) . '/Fixtures/classmap/StripNoise.php',
            'Foo\\SlashedA' => realpath(__DIR__) . '/Fixtures/classmap/BackslashLineEndingString.php',
            'Foo\\SlashedB' => realpath(__DIR__) . '/Fixtures/classmap/BackslashLineEndingString.php',
            'Unicode\\↑\\↑' => realpath(__DIR__) . '/Fixtures/classmap/Unicode.php',
            'ShortOpenTag' => realpath(__DIR__) . '/Fixtures/classmap/ShortOpenTag.php',
            'Smarty_Internal_Compile_Block' => realpath(__DIR__) . '/Fixtures/classmap/InvalidUnicode.php',
            'Smarty_Internal_Compile_Blockclose' => realpath(__DIR__) . '/Fixtures/classmap/InvalidUnicode.php',
            'ShortOpenTagDocblock' => realpath(__DIR__) . '/Fixtures/classmap/ShortOpenTagDocblock.php',
        );

        $data = array(
            array(__DIR__ . '/Fixtures/Namespaced', array(
                'Namespaced\\Bar' => realpath(__DIR__) . '/Fixtures/Namespaced/Bar.inc',
                'Namespaced\\Foo' => realpath(__DIR__) . '/Fixtures/Namespaced/Foo.php',
                'Namespaced\\Baz' => realpath(__DIR__) . '/Fixtures/Namespaced/Baz.php',
            )),
            array(__DIR__ . '/Fixtures/beta/NamespaceCollision', array(
                'NamespaceCollision\\A\\B\\Bar' => realpath(__DIR__) . '/Fixtures/beta/NamespaceCollision/A/B/Bar.php',
                'NamespaceCollision\\A\\B\\Foo' => realpath(__DIR__) . '/Fixtures/beta/NamespaceCollision/A/B/Foo.php',
            )),
            array(__DIR__ . '/Fixtures/Pearlike', array(
                'Pearlike_Foo' => realpath(__DIR__) . '/Fixtures/Pearlike/Foo.php',
                'Pearlike_Bar' => realpath(__DIR__) . '/Fixtures/Pearlike/Bar.php',
                'Pearlike_Baz' => realpath(__DIR__) . '/Fixtures/Pearlike/Baz.php',
            )),
            array(__DIR__ . '/Fixtures/classmap', $classmap),
            array(__DIR__ . '/Fixtures/template', array()),
        );

        $data[] = array(__DIR__ . '/Fixtures/php5.4', array(
            'TFoo' => __DIR__ . '/Fixtures/php5.4/traits.php',
            'CFoo' => __DIR__ . '/Fixtures/php5.4/traits.php',
            'Foo\\TBar' => __DIR__ . '/Fixtures/php5.4/traits.php',
            'Foo\\IBar' => __DIR__ . '/Fixtures/php5.4/traits.php',
            'Foo\\TFooBar' => __DIR__ . '/Fixtures/php5.4/traits.php',
            'Foo\\CBar' => __DIR__ . '/Fixtures/php5.4/traits.php',
        ));

        $data[] = array(__DIR__ . '/Fixtures/php7.0', array(
            'Dummy\Test\AnonClassHolder' => __DIR__ . '/Fixtures/php7.0/anonclass.php',
        ));

        if (PHP_VERSION_ID >= 80100) {
            $data[] = array(__DIR__ . '/Fixtures/php8.1', array(
                'RolesBasicEnum' => __DIR__ . '/Fixtures/php8.1/enum_basic.php',
                'RolesBackedEnum' => __DIR__ . '/Fixtures/php8.1/enum_backed.php',
                'RolesClassLikeEnum' => __DIR__ . '/Fixtures/php8.1/enum_class_semantics.php',
                'Foo\Bar\RolesClassLikeNamespacedEnum' => __DIR__ . '/Fixtures/php8.1/enum_namespaced.php',
            ));
        }

        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.3', '>=')) {
            $data[] = array(__DIR__ . '/Fixtures/hhvm3.3', array(
                'FooEnum' => __DIR__ . '/Fixtures/hhvm3.3/HackEnum.php',
                'Foo\BarEnum' => __DIR__ . '/Fixtures/hhvm3.3/NamespacedHackEnum.php',
                'GenericsClass' => __DIR__ . '/Fixtures/hhvm3.3/Generics.php',
            ));
        }

        return $data;
    }

    public function testCreateMapFinderSupport(): void
    {
        $this->checkIfFinderIsAvailable();

        $finder = new Finder();
        $finder->files()->in(__DIR__ . '/Fixtures/beta/NamespaceCollision');

        $this->assertEqualsNormalized(array(
            'NamespaceCollision\\A\\B\\Bar' => realpath(__DIR__) . '/Fixtures/beta/NamespaceCollision/A/B/Bar.php',
            'NamespaceCollision\\A\\B\\Foo' => realpath(__DIR__) . '/Fixtures/beta/NamespaceCollision/A/B/Foo.php',
        ), ClassMapGenerator::createMap($finder));
    }

    public function testFindClassesThrowsWhenFileDoesNotExist(): void
    {
        $r = new \ReflectionClass('Composer\\Autoload\\ClassMapGenerator');
        $find = $r->getMethod('findClasses');
        $find->setAccessible(true);

        self::expectException('RuntimeException');
        self::expectExceptionMessage('does not exist');
        $find->invoke(null, __DIR__ . '/no-file');
    }

    public function testAmbiguousReference(): void
    {
        $this->checkIfFinderIsAvailable();

        $tempDir = self::getUniqueTmpDirectory();
        $this->ensureDirectoryExistsAndClear($tempDir . '/other');

        $finder = new Finder();
        $finder->files()->in($tempDir);

        $io = $this->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock();

        file_put_contents($tempDir . '/A.php', "<?php\nclass A {}");
        file_put_contents($tempDir . '/other/A.php', "<?php\nclass A {}");

        $a = realpath($tempDir . '/A.php');
        $b = realpath($tempDir . '/other/A.php');
        $msg = '';

        $io->expects($this->once())
            ->method('writeError')
            ->will($this->returnCallback(function ($text) use (&$msg): void {
                $msg = $text;
            }));

        $messages = array(
            '<warning>Warning: Ambiguous class resolution, "A" was found in both "' . $a . '" and "' . $b . '", the first will be used.</warning>',
            '<warning>Warning: Ambiguous class resolution, "A" was found in both "' . $b . '" and "' . $a . '", the first will be used.</warning>',
        );

        ClassMapGenerator::createMap($finder, null, $io);

        $this->assertContains($msg, $messages, $msg . ' not found in expected messages (' . var_export($messages, true) . ')');

        $fs = new Filesystem();
        $fs->removeDirectory($tempDir);
    }

    /**
     * If one file has a class or interface defined more than once,
     * an ambiguous reference warning should not be produced
     */
    public function testUnambiguousReference(): void
    {
        $tempDir = self::getUniqueTmpDirectory();

        file_put_contents($tempDir . '/A.php', "<?php\nclass A {}");
        file_put_contents(
            $tempDir . '/B.php',
            "<?php
                if (true) {
                    interface B {}
                } else {
                    interface B extends Iterator {}
                }
            "
        );

        foreach (array('test', 'fixture', 'example') as $keyword) {
            if (!is_dir($tempDir . '/' . $keyword)) {
                mkdir($tempDir . '/' . $keyword, 0777, true);
            }
            file_put_contents($tempDir . '/' . $keyword . '/A.php', "<?php\nclass A {}");
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

    public function testCreateMapThrowsWhenDirectoryDoesNotExist(): void
    {
        self::expectException('RuntimeException');
        self::expectExceptionMessage('Could not scan for classes inside');
        ClassMapGenerator::createMap(__DIR__ . '/no-file.no-foler');
    }

    public function testDump(): void
    {
        $tempDir = self::getUniqueTmpDirectory();

        $resultFile = $tempDir . '/result.txt';
        $fileInDirectory = $tempDir . DIRECTORY_SEPARATOR . 'TestClass.php';

        file_put_contents($fileInDirectory, "<?php class TestClass {} ?>");

        ClassMapGenerator::dump(array($tempDir), $resultFile);

        $fileInDirectory = str_replace('\\', '\\\\', $fileInDirectory);
        $this->assertStringEqualsFile($resultFile, "<?php return array (\n  'TestClass' => '$fileInDirectory',\n);");

        $fs = new Filesystem();
        $fs->removeDirectory($tempDir);
    }

    public function testCreateMapDoesNotHitRegexBacktraceLimit(): void
    {
        $expected = array(
            'Foo\\StripNoise' => realpath(__DIR__) . '/Fixtures/pcrebacktracelimit/StripNoise.php',
            'Foo\\VeryLongHeredoc' => realpath(__DIR__) . '/Fixtures/pcrebacktracelimit/VeryLongHeredoc.php',
            'Foo\\ClassAfterLongHereDoc' => realpath(__DIR__) . '/Fixtures/pcrebacktracelimit/VeryLongHeredoc.php',
            'Foo\\VeryLongPHP73Heredoc' => realpath(__DIR__) . '/Fixtures/pcrebacktracelimit/VeryLongPHP73Heredoc.php',
            'Foo\\VeryLongPHP73Nowdoc' => realpath(__DIR__) . '/Fixtures/pcrebacktracelimit/VeryLongPHP73Nowdoc.php',
            'Foo\\ClassAfterLongNowDoc' => realpath(__DIR__) . '/Fixtures/pcrebacktracelimit/VeryLongPHP73Nowdoc.php',
            'Foo\\VeryLongNowdoc' => realpath(__DIR__) . '/Fixtures/pcrebacktracelimit/VeryLongNowdoc.php',
        );

        ini_set('pcre.backtrack_limit', '30000');
        $result = ClassMapGenerator::createMap(__DIR__ . '/Fixtures/pcrebacktracelimit');
        ini_restore('pcre.backtrack_limit');

        $this->assertEqualsNormalized($expected, $result);
    }

    /**
     * @param array<class-string> $expected
     * @param array<class-string> $actual
     * @param string $message
     * @return  void
     */
    protected function assertEqualsNormalized(array $expected, array $actual, string $message = ''): void
    {
        foreach ($expected as $ns => $path) {
            $expected[$ns] = strtr($path, '\\', '/');
        }
        foreach ($actual as $ns => $path) {
            $actual[$ns] = strtr($path, '\\', '/');
        }
        $this->assertEquals($expected, $actual, $message);
    }

    /** @return void */
    private function checkIfFinderIsAvailable(): void
    {
        if (!class_exists('Symfony\\Component\\Finder\\Finder')) {
            $this->markTestSkipped('Finder component is not available');
        }
    }
}
