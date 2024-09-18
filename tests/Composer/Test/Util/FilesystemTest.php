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

namespace Composer\Test\Util;

use Composer\Util\Platform;
use Composer\Util\Filesystem;
use Composer\Test\TestCase;

class FilesystemTest extends TestCase
{
    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var string
     */
    private $workingDir;

    /**
     * @var string
     */
    private $testFile;

    public function setUp(): void
    {
        $this->fs = new Filesystem;
        $this->workingDir = self::getUniqueTmpDirectory();
        $this->testFile = self::getUniqueTmpDirectory() . '/composer_test_file';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->workingDir)) {
            $this->fs->removeDirectory($this->workingDir);
        }
        if (is_file($this->testFile)) {
            $this->fs->removeDirectory(dirname($this->testFile));
        }
    }

    /**
     * @dataProvider providePathCouplesAsCode
     */
    public function testFindShortestPathCode(string $a, string $b, bool $directory, string $expected, bool $static = false, bool $preferRelative = false): void
    {
        $fs = new Filesystem;
        self::assertEquals($expected, $fs->findShortestPathCode($a, $b, $directory, $static, $preferRelative));
    }

    public static function providePathCouplesAsCode(): array
    {
        return [
            ['/foo/bar', '/foo/bar', false, "__FILE__"],
            ['/foo/bar', '/foo/baz', false, "__DIR__.'/baz'"],
            ['/foo/bin/run', '/foo/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"],
            ['/foo/bin/run', '/bar/bin/run', false, "'/bar/bin/run'"],
            ['c:/bin/run', 'c:/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"],
            ['c:\\bin\\run', 'c:/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"],
            ['c:/bin/run', 'D:/vendor/acme/bin/run', false, "'D:/vendor/acme/bin/run'"],
            ['c:\\bin\\run', 'd:/vendor/acme/bin/run', false, "'D:/vendor/acme/bin/run'"],
            ['/foo/bar', '/foo/bar', true, "__DIR__"],
            ['/foo/bar/', '/foo/bar', true, "__DIR__"],
            ['/foo', '/baz', true, "dirname(__DIR__).'/baz'"],
            ['/foo/bar', '/foo/baz', true, "dirname(__DIR__).'/baz'"],
            ['/foo/bin/run', '/foo/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"],
            ['/foo/bin/run', '/bar/bin/run', true, "'/bar/bin/run'"],
            ['/app/vendor/foo/bar', '/lib', true, "dirname(dirname(dirname(dirname(__DIR__)))).'/lib'", false, true],
            ['/bin/run', '/bin/run', true, "__DIR__"],
            ['c:/bin/run', 'C:\\bin/run', true, "__DIR__"],
            ['c:/bin/run', 'c:/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"],
            ['c:\\bin\\run', 'c:/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"],
            ['c:/bin/run', 'd:/vendor/acme/bin/run', true, "'D:/vendor/acme/bin/run'"],
            ['c:\\bin\\run', 'd:/vendor/acme/bin/run', true, "'D:/vendor/acme/bin/run'"],
            ['C:/Temp/test', 'C:\Temp', true, "dirname(__DIR__)"],
            ['C:/Temp', 'C:\Temp\test', true, "__DIR__ . '/test'"],
            ['/tmp/test', '/tmp', true, "dirname(__DIR__)"],
            ['/tmp', '/tmp/test', true, "__DIR__ . '/test'"],
            ['C:/Temp', 'c:\Temp\test', true, "__DIR__ . '/test'"],
            ['/tmp/test/./', '/tmp/test/', true, '__DIR__'],
            ['/tmp/test/../vendor', '/tmp/test', true, "dirname(__DIR__).'/test'"],
            ['/tmp/test/.././vendor', '/tmp/test', true, "dirname(__DIR__).'/test'"],
            ['C:/Temp', 'c:\Temp\..\..\test', true, "dirname(__DIR__).'/test'"],
            ['C:/Temp/../..', 'd:\Temp\..\..\test', true, "'D:/test'"],
            ['/foo/bar', '/foo/bar_vendor', true, "dirname(__DIR__).'/bar_vendor'"],
            ['/foo/bar_vendor', '/foo/bar', true, "dirname(__DIR__).'/bar'"],
            ['/foo/bar_vendor', '/foo/bar/src', true, "dirname(__DIR__).'/bar/src'"],
            ['/foo/bar_vendor/src2', '/foo/bar/src/lib', true, "dirname(dirname(__DIR__)).'/bar/src/lib'"],

            // static use case
            ['/tmp/test/../vendor', '/tmp/test', true, "__DIR__ . '/..'.'/test'", true],
            ['/tmp/test/.././vendor', '/tmp/test', true, "__DIR__ . '/..'.'/test'", true],
            ['C:/Temp', 'c:\Temp\..\..\test', true, "__DIR__ . '/..'.'/test'", true],
            ['C:/Temp/../..', 'd:\Temp\..\..\test', true, "'D:/test'", true],
            ['/foo/bar', '/foo/bar_vendor', true, "__DIR__ . '/..'.'/bar_vendor'", true],
            ['/foo/bar_vendor', '/foo/bar', true, "__DIR__ . '/..'.'/bar'", true],
            ['/foo/bar_vendor', '/foo/bar/src', true, "__DIR__ . '/..'.'/bar/src'", true],
            ['/foo/bar_vendor/src2', '/foo/bar/src/lib', true, "__DIR__ . '/../..'.'/bar/src/lib'", true],
        ];
    }

    /**
     * @dataProvider providePathCouples
     */
    public function testFindShortestPath(string $a, string $b, string $expected, bool $directory = false, bool $preferRelative = false): void
    {
        $fs = new Filesystem;
        self::assertEquals($expected, $fs->findShortestPath($a, $b, $directory, $preferRelative));
    }

    public static function providePathCouples(): array
    {
        return [
            ['/foo/bar', '/foo/bar', "./bar"],
            ['/foo/bar', '/foo/baz', "./baz"],
            ['/foo/bar/', '/foo/baz', "./baz"],
            ['/foo/bar', '/foo/bar', "./", true],
            ['/foo/bar', '/foo/baz', "../baz", true],
            ['/foo/bar/', '/foo/baz', "../baz", true],
            ['C:/foo/bar/', 'c:/foo/baz', "../baz", true],
            ['/foo/bin/run', '/foo/vendor/acme/bin/run', "../vendor/acme/bin/run"],
            ['/foo/bin/run', '/bar/bin/run', "/bar/bin/run"],
            ['/foo/bin/run', '/bar/bin/run', "/bar/bin/run", true],
            ['c:/foo/bin/run', 'd:/bar/bin/run', "D:/bar/bin/run", true],
            ['c:/bin/run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"],
            ['c:\\bin\\run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"],
            ['c:/bin/run', 'd:/vendor/acme/bin/run', "D:/vendor/acme/bin/run"],
            ['c:\\bin\\run', 'd:/vendor/acme/bin/run', "D:/vendor/acme/bin/run"],
            ['C:/Temp/test', 'C:\Temp', "./"],
            ['/tmp/test', '/tmp', "./"],
            ['C:/Temp/test/sub', 'C:\Temp', "../"],
            ['/tmp/test/sub', '/tmp', "../"],
            ['/tmp/test/sub', '/tmp', "../../", true],
            ['c:/tmp/test/sub', 'c:/tmp', "../../", true],
            ['/tmp', '/tmp/test', "test"],
            ['C:/Temp', 'C:\Temp\test', "test"],
            ['C:/Temp', 'c:\Temp\test', "test"],
            ['/tmp/test/./', '/tmp/test', './', true],
            ['/tmp/test/../vendor', '/tmp/test', '../test', true],
            ['/tmp/test/.././vendor', '/tmp/test', '../test', true],
            ['C:/Temp', 'c:\Temp\..\..\test', "../test", true],
            ['C:/Temp/../..', 'c:\Temp\..\..\test', "./test", true],
            ['C:/Temp/../..', 'D:\Temp\..\..\test', "D:/test", true],
            ['/app/vendor/foo/bar', '/lib', '../../../../lib', true, true],
            ['/tmp', '/tmp/../../test', '../test', true],
            ['/tmp', '/test', '../test', true],
            ['/foo/bar', '/foo/bar_vendor', '../bar_vendor', true],
            ['/foo/bar_vendor', '/foo/bar', '../bar', true],
            ['/foo/bar_vendor', '/foo/bar/src', '../bar/src', true],
            ['/foo/bar_vendor/src2', '/foo/bar/src/lib', '../../bar/src/lib', true],
            ['C:/', 'C:/foo/bar/', "foo/bar", true],
        ];
    }

    /**
     * @group GH-1339
     */
    public function testRemoveDirectoryPhp(): void
    {
        @mkdir($this->workingDir . "/level1/level2", 0777, true);
        file_put_contents($this->workingDir . "/level1/level2/hello.txt", "hello world");

        $fs = new Filesystem;
        self::assertTrue($fs->removeDirectoryPhp($this->workingDir));
        self::assertFileDoesNotExist($this->workingDir . "/level1/level2/hello.txt");
    }

    public function testFileSize(): void
    {
        file_put_contents($this->testFile, 'Hello');

        $fs = new Filesystem;
        self::assertGreaterThanOrEqual(5, $fs->size($this->testFile));
    }

    public function testDirectorySize(): void
    {
        @mkdir($this->workingDir, 0777, true);
        file_put_contents($this->workingDir."/file1.txt", 'Hello');
        file_put_contents($this->workingDir."/file2.txt", 'World');

        $fs = new Filesystem;
        self::assertGreaterThanOrEqual(10, $fs->size($this->workingDir));
    }

    /**
     * @dataProvider provideNormalizedPaths
     */
    public function testNormalizePath(string $expected, string $actual): void
    {
        $fs = new Filesystem;
        self::assertEquals($expected, $fs->normalizePath($actual));
    }

    public static function provideNormalizedPaths(): array
    {
        return [
            ['../foo', '../foo'],
            ['C:/foo/bar', 'c:/foo//bar'],
            ['C:/foo/bar', 'C:/foo/./bar'],
            ['C:/foo/bar', 'C://foo//bar'],
            ['C:/foo/bar', 'C:///foo//bar'],
            ['C:/bar', 'C:/foo/../bar'],
            ['/bar', '/foo/../bar/'],
            ['phar://C:/Foo', 'phar://c:/Foo/Bar/..'],
            ['phar://C:/Foo', 'phar://c:///Foo/Bar/..'],
            ['phar://C:/', 'phar://c:/Foo/Bar/../../../..'],
            ['/', '/Foo/Bar/../../../..'],
            ['/', '/'],
            ['/', '//'],
            ['/', '///'],
            ['/Foo', '///Foo'],
            ['C:/', 'c:\\'],
            ['../src', 'Foo/Bar/../../../src'],
            ['C:../b', 'c:.\\..\\a\\..\\b'],
            ['phar://C:../Foo', 'phar://c:../Foo'],
            ['//foo/bar', '\\\\foo\\bar'],
        ];
    }

    /**
     * @link https://github.com/composer/composer/issues/3157
     * @requires function symlink
     */
    public function testUnlinkSymlinkedDirectory(): void
    {
        $basepath = $this->workingDir;
        $symlinked = $basepath . "/linked";
        @mkdir($basepath . "/real", 0777, true);
        touch($basepath . "/real/FILE");

        $result = @symlink($basepath . "/real", $symlinked);

        if (!$result) {
            $this->markTestSkipped('Symbolic links for directories not supported on this platform');
        }

        if (!is_dir($symlinked)) {
            $this->fail('Precondition assertion failed (is_dir is false on symbolic link to directory).');
        }

        $fs = new Filesystem();
        $result = $fs->unlink($symlinked);
        self::assertTrue($result);
        self::assertFileDoesNotExist($symlinked);
    }

    /**
     * @link https://github.com/composer/composer/issues/3144
     * @requires function symlink
     */
    public function testRemoveSymlinkedDirectoryWithTrailingSlash(): void
    {
        @mkdir($this->workingDir . "/real", 0777, true);
        touch($this->workingDir . "/real/FILE");
        $symlinked = $this->workingDir . "/linked";
        $symlinkedTrailingSlash = $symlinked . "/";

        $result = @symlink($this->workingDir . "/real", $symlinked);

        if (!$result) {
            $this->markTestSkipped('Symbolic links for directories not supported on this platform');
        }

        if (!is_dir($symlinked)) {
            $this->fail('Precondition assertion failed (is_dir is false on symbolic link to directory).');
        }

        if (!is_dir($symlinkedTrailingSlash)) {
            $this->fail('Precondition assertion failed (is_dir false w trailing slash).');
        }

        $fs = new Filesystem();

        $result = $fs->removeDirectory($symlinkedTrailingSlash);
        self::assertTrue($result);
        self::assertFileDoesNotExist($symlinkedTrailingSlash);
        self::assertFileDoesNotExist($symlinked);
    }

    public function testJunctions(): void
    {
        @mkdir($this->workingDir . '/real/nesting/testing', 0777, true);
        $fs = new Filesystem();

        // Non-Windows systems do not support this and will return false on all tests, and an exception on creation
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            self::assertFalse($fs->isJunction($this->workingDir));
            self::assertFalse($fs->removeJunction($this->workingDir));
            self::expectException('LogicException');
            self::expectExceptionMessage('not available on non-Windows platform');
        }

        $target = $this->workingDir . '/real/../real/nesting';
        $junction = $this->workingDir . '/junction';

        // Create and detect junction
        $fs->junction($target, $junction);
        self::assertTrue($fs->isJunction($junction), $junction . ': is a junction');
        self::assertFalse($fs->isJunction($target), $target . ': is not a junction');
        self::assertTrue($fs->isJunction($target . '/../../junction'), $target . '/../../junction: is a junction');
        self::assertFalse($fs->isJunction($junction . '/../real'), $junction . '/../real: is not a junction');
        self::assertTrue($fs->isJunction($junction . '/../junction'), $junction . '/../junction: is a junction');

        // Remove junction
        self::assertDirectoryExists($junction, $junction . ' is a directory');
        self::assertTrue($fs->removeJunction($junction), $junction . ' has been removed');
        self::assertDirectoryDoesNotExist($junction, $junction . ' is not a directory');
    }

    public function testOverrideJunctions(): void
    {
        if (!Platform::isWindows()) {
            $this->markTestSkipped('Only runs on windows');
        }

        @mkdir($this->workingDir.'/real/nesting/testing', 0777, true);
        $fs = new Filesystem();

        $old_target = $this->workingDir.'/real/nesting/testing';
        $target = $this->workingDir.'/real/../real/nesting';
        $junction = $this->workingDir.'/junction';

        // Override non-broken junction
        $fs->junction($old_target, $junction);
        $fs->junction($target, $junction);

        self::assertTrue($fs->isJunction($junction), $junction.': is a junction');
        self::assertTrue($fs->isJunction($target.'/../../junction'), $target.'/../../junction: is a junction');

        //Remove junction
        self::assertTrue($fs->removeJunction($junction), $junction . ' has been removed');

        // Override broken junction
        $fs->junction($old_target, $junction);
        $fs->removeDirectory($old_target);
        $fs->junction($target, $junction);

        self::assertTrue($fs->isJunction($junction), $junction.': is a junction');
        self::assertTrue($fs->isJunction($target.'/../../junction'), $target.'/../../junction: is a junction');
    }

    public function testCopy(): void
    {
        @mkdir($this->workingDir . '/foo/bar', 0777, true);
        @mkdir($this->workingDir . '/foo/baz', 0777, true);
        file_put_contents($this->workingDir . '/foo/foo.file', 'foo');
        file_put_contents($this->workingDir . '/foo/bar/foobar.file', 'foobar');
        file_put_contents($this->workingDir . '/foo/baz/foobaz.file', 'foobaz');
        file_put_contents($this->testFile, 'testfile');

        $fs = new Filesystem();

        $result1 = $fs->copy($this->workingDir . '/foo', $this->workingDir . '/foop');
        self::assertTrue($result1, 'Copying directory failed.');
        self::assertDirectoryExists($this->workingDir . '/foop', 'Not a directory: ' . $this->workingDir . '/foop');
        self::assertDirectoryExists($this->workingDir . '/foop/bar', 'Not a directory: ' . $this->workingDir . '/foop/bar');
        self::assertDirectoryExists($this->workingDir . '/foop/baz', 'Not a directory: ' . $this->workingDir . '/foop/baz');
        self::assertFileExists($this->workingDir . '/foop/foo.file', 'Not a file: ' . $this->workingDir . '/foop/foo.file');
        self::assertFileExists($this->workingDir . '/foop/bar/foobar.file', 'Not a file: ' . $this->workingDir . '/foop/bar/foobar.file');
        self::assertFileExists($this->workingDir . '/foop/baz/foobaz.file', 'Not a file: ' . $this->workingDir . '/foop/baz/foobaz.file');

        $result2 = $fs->copy($this->testFile, $this->workingDir . '/testfile.file');
        self::assertTrue($result2);
        self::assertFileExists($this->workingDir . '/testfile.file');
    }

    public function testCopyThenRemove(): void
    {
        @mkdir($this->workingDir . '/foo/bar', 0777, true);
        @mkdir($this->workingDir . '/foo/baz', 0777, true);
        file_put_contents($this->workingDir . '/foo/foo.file', 'foo');
        file_put_contents($this->workingDir . '/foo/bar/foobar.file', 'foobar');
        file_put_contents($this->workingDir . '/foo/baz/foobaz.file', 'foobaz');
        file_put_contents($this->testFile, 'testfile');

        $fs = new Filesystem();

        $fs->copyThenRemove($this->testFile, $this->workingDir . '/testfile.file');
        self::assertFileDoesNotExist($this->testFile, 'Still a file: ' . $this->testFile);

        $fs->copyThenRemove($this->workingDir . '/foo', $this->workingDir . '/foop');
        self::assertFileDoesNotExist($this->workingDir . '/foo/baz/foobaz.file', 'Still a file: ' . $this->workingDir . '/foo/baz/foobaz.file');
        self::assertFileDoesNotExist($this->workingDir . '/foo/bar/foobar.file', 'Still a file: ' . $this->workingDir . '/foo/bar/foobar.file');
        self::assertFileDoesNotExist($this->workingDir . '/foo/foo.file', 'Still a file: ' . $this->workingDir . '/foo/foo.file');
        self::assertDirectoryDoesNotExist($this->workingDir . '/foo/baz', 'Still a directory: ' . $this->workingDir . '/foo/baz');
        self::assertDirectoryDoesNotExist($this->workingDir . '/foo/bar', 'Still a directory: ' . $this->workingDir . '/foo/bar');
        self::assertDirectoryDoesNotExist($this->workingDir . '/foo', 'Still a directory: ' . $this->workingDir . '/foo');
    }
}
