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

namespace Composer\Test\Autoload;

use Composer\Autoload\ClassLoader;
use Composer\Test\TestCase;

/**
 * Tests the Composer\Autoload\ClassLoader class.
 */
class ClassLoaderTest extends TestCase
{
    /**
     * Tests regular PSR-0 and PSR-4 class loading.
     *
     * @dataProvider getLoadClassTests
     *
     * @param string $class The fully-qualified class name to test, without preceding namespace separator.
     */
    public function testLoadClass(string $class): void
    {
        $loader = new ClassLoader();
        $loader->add('Namespaced\\', __DIR__ . '/Fixtures');
        $loader->add('Pearlike_', __DIR__ . '/Fixtures');
        $loader->addPsr4('ShinyVendor\\ShinyPackage\\', __DIR__ . '/Fixtures');
        $loader->loadClass($class);
        self::assertTrue(class_exists($class, false), "->loadClass() loads '$class'");
    }

    /**
     * Provides arguments for ->testLoadClass().
     *
     * @return array<array<string>> Array of parameter sets to test with.
     */
    public static function getLoadClassTests(): array
    {
        return [
            ['Namespaced\\Foo'],
            ['Pearlike_Foo'],
            ['ShinyVendor\\ShinyPackage\\SubNamespace\\Foo'],
        ];
    }

    /**
     * Tests Moto file resolution.
     *
     * @dataProvider getMotoFindFileTests
     *
     * @param string $class    The fully-qualified class name to test.
     * @param string $expected The expected file path suffix.
     */
    public function testMotoFindFile(string $class, string $expected): void
    {
        $loader = new ClassLoader();
        $loader->addMoto('MotoVendor\\MotoPackage\\', __DIR__ . '/Fixtures/MotoVendor');
        $file = $loader->findFile($class);
        self::assertNotFalse($file, "->findFile() finds '$class'");
        self::assertStringEndsWith($expected, $file, "->findFile() resolves '$class' to the correct file");
    }

    /**
     * Provides arguments for ->testMotoFindFile().
     *
     * @return array<array<string>> Array of parameter sets to test with.
     */
    public static function getMotoFindFileTests(): array
    {
        return [
            ['MotoVendor\\MotoPackage\\SubNamespace\\Foo', 'SubNamespace' . DIRECTORY_SEPARATOR . 'Foo.php'],
            ['MotoVendor\\MotoPackage\\SubNamespace\\Foo_Bar', 'SubNamespace' . DIRECTORY_SEPARATOR . 'Foo.php'],
            ['MotoVendor\\MotoPackage\\SubNamespace\\Foo_Baz', 'SubNamespace' . DIRECTORY_SEPARATOR . 'Foo.php'],
        ];
    }

    /**
     * Tests that Moto resolution returns false when the partial name
     * is empty after underscore stripping.
     *
     * @dataProvider getMotoEmptyPartialNameTests
     */
    public function testMotoEmptyPartialNameReturnsFalse(string $class): void
    {
        $loader = new ClassLoader();
        $loader->addMoto('MotoVendor\\MotoPackage\\', __DIR__ . '/Fixtures/MotoVendor');
        self::assertFalse($loader->findFile($class));
    }

    /**
     * @return array<array<string>>
     */
    public static function getMotoEmptyPartialNameTests(): array
    {
        return [
            ['MotoVendor\\MotoPackage\\_Foo'],
            ['MotoVendor\\MotoPackage\\SubNamespace\\_Foo'],
            ['MotoVendor\\MotoPackage\\_'],
        ];
    }

    /**
     * getPrefixes method should return empty array if ClassLoader does not have any psr-0 configuration
     */
    public function testGetPrefixesWithNoPSR0Configuration(): void
    {
        $loader = new ClassLoader();
        self::assertEmpty($loader->getPrefixes());
    }

    public function testSerializability(): void
    {
        $loader = new ClassLoader();
        $loader->add('Pearlike_', __DIR__ . '/Fixtures');
        $loader->add('', __DIR__ . '/FALLBACK');
        $loader->addPsr4('ShinyVendor\\ShinyPackage\\', __DIR__ . '/Fixtures');
        $loader->addPsr4('', __DIR__ . '/FALLBACKPSR4');
        $loader->addMoto('MotoVendor\\MotoPackage\\', __DIR__ . '/Fixtures/MotoVendor');
        $loader->addMoto('', __DIR__ . '/FALLBACKMOTO');
        $loader->addClassMap(['A' => '', 'B' => 'path']);
        $loader->setApcuPrefix('prefix');
        $loader->setClassMapAuthoritative(true);
        $loader->setUseIncludePath(true);

        $loader2 = unserialize(serialize($loader));
        self::assertInstanceOf(ClassLoader::class, $loader2);
        self::assertSame($loader->getApcuPrefix(), $loader2->getApcuPrefix());
        self::assertSame($loader->getClassMap(), $loader2->getClassMap());
        self::assertSame($loader->getFallbackDirs(), $loader2->getFallbackDirs());
        self::assertSame($loader->getFallbackDirsPsr4(), $loader2->getFallbackDirsPsr4());
        self::assertSame($loader->getFallbackDirsMoto(), $loader2->getFallbackDirsMoto());
        self::assertSame($loader->getPrefixes(), $loader2->getPrefixes());
        self::assertSame($loader->getPrefixesPsr4(), $loader2->getPrefixesPsr4());
        self::assertSame($loader->getPrefixesMoto(), $loader2->getPrefixesMoto());
        self::assertSame($loader->getUseIncludePath(), $loader2->getUseIncludePath());
    }
}
