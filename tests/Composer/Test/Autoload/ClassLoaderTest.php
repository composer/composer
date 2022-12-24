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
        $this->assertTrue(class_exists($class, false), "->loadClass() loads '$class'");
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
     * getPrefixes method should return empty array if ClassLoader does not have any psr-0 configuration
     */
    public function testGetPrefixesWithNoPSR0Configuration(): void
    {
        $loader = new ClassLoader();
        $this->assertEmpty($loader->getPrefixes());
    }

    public function testSerializability(): void
    {
        $loader = new ClassLoader();
        $loader->add('Pearlike_', __DIR__ . '/Fixtures');
        $loader->add('', __DIR__ . '/FALLBACK');
        $loader->addPsr4('ShinyVendor\\ShinyPackage\\', __DIR__ . '/Fixtures');
        $loader->addPsr4('', __DIR__ . '/FALLBACKPSR4');
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
        self::assertSame($loader->getPrefixes(), $loader2->getPrefixes());
        self::assertSame($loader->getPrefixesPsr4(), $loader2->getPrefixesPsr4());
        self::assertSame($loader->getUseIncludePath(), $loader2->getUseIncludePath());
    }
}
