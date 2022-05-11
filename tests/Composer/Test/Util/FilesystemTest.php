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
     *
     * @param string $a
     * @param string $b
     * @param bool   $directory
     * @param string $expected
     * @param bool   $static
     */
    public function testFindShortestPathCode(string $a, string $b, bool $directory, string $expected, bool $static = false): void
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->findShortestPathCode($a, $b, $directory, $static));
    }

    public function providePathCouplesAsCode(): array
    {
        return array(
            array('/foo/bar', '/foo/bar', false, "__FILE__"),
            array('/foo/bar', '/foo/baz', false, "__DIR__.'/baz'"),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('/foo/bin/run', '/bar/bin/run', false, "'/bar/bin/run'"),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('c:/bin/run', 'D:/vendor/acme/bin/run', false, "'D:/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', false, "'D:/vendor/acme/bin/run'"),
            array('/foo/bar', '/foo/bar', true, "__DIR__"),
            array('/foo/bar/', '/foo/bar', true, "__DIR__"),
            array('/foo/bar', '/foo/baz', true, "dirname(__DIR__).'/baz'"),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"),
            array('/foo/bin/run', '/bar/bin/run', true, "'/bar/bin/run'"),
            array('/bin/run', '/bin/run', true, "__DIR__"),
            array('c:/bin/run', 'C:\\bin/run', true, "__DIR__"),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', true, "'D:/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', true, "'D:/vendor/acme/bin/run'"),
            array('C:/Temp/test', 'C:\Temp', true, "dirname(__DIR__)"),
            array('C:/Temp', 'C:\Temp\test', true, "__DIR__ . '/test'"),
            array('/tmp/test', '/tmp', true, "dirname(__DIR__)"),
            array('/tmp', '/tmp/test', true, "__DIR__ . '/test'"),
            array('C:/Temp', 'c:\Temp\test', true, "__DIR__ . '/test'"),
            array('/tmp/test/./', '/tmp/test/', true, '__DIR__'),
            array('/tmp/test/../vendor', '/tmp/test', true, "dirname(__DIR__).'/test'"),
            array('/tmp/test/.././vendor', '/tmp/test', true, "dirname(__DIR__).'/test'"),
            array('C:/Temp', 'c:\Temp\..\..\test', true, "dirname(__DIR__).'/test'"),
            array('C:/Temp/../..', 'd:\Temp\..\..\test', true, "'D:/test'"),
            array('/foo/bar', '/foo/bar_vendor', true, "dirname(__DIR__).'/bar_vendor'"),
            array('/foo/bar_vendor', '/foo/bar', true, "dirname(__DIR__).'/bar'"),
            array('/foo/bar_vendor', '/foo/bar/src', true, "dirname(__DIR__).'/bar/src'"),
            array('/foo/bar_vendor/src2', '/foo/bar/src/lib', true, "dirname(dirname(__DIR__)).'/bar/src/lib'"),

            // static use case
            array('/tmp/test/../vendor', '/tmp/test', true, "__DIR__ . '/..'.'/test'", true),
            array('/tmp/test/.././vendor', '/tmp/test', true, "__DIR__ . '/..'.'/test'", true),
            array('C:/Temp', 'c:\Temp\..\..\test', true, "__DIR__ . '/..'.'/test'", true),
            array('C:/Temp/../..', 'd:\Temp\..\..\test', true, "'D:/test'", true),
            array('/foo/bar', '/foo/bar_vendor', true, "__DIR__ . '/..'.'/bar_vendor'", true),
            array('/foo/bar_vendor', '/foo/bar', true, "__DIR__ . '/..'.'/bar'", true),
            array('/foo/bar_vendor', '/foo/bar/src', true, "__DIR__ . '/..'.'/bar/src'", true),
            array('/foo/bar_vendor/src2', '/foo/bar/src/lib', true, "__DIR__ . '/../..'.'/bar/src/lib'", true),
        );
    }

    /**
     * @dataProvider providePathCouples
     *
     * @param string $a
     * @param string $b
     * @param string $expected
     * @param bool   $directory
     */
    public function testFindShortestPath(string $a, string $b, string $expected, bool $directory = false): void
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->findShortestPath($a, $b, $directory));
    }

    public function providePathCouples(): array
    {
        return array(
            array('/foo/bar', '/foo/bar', "./bar"),
            array('/foo/bar', '/foo/baz', "./baz"),
            array('/foo/bar/', '/foo/baz', "./baz"),
            array('/foo/bar', '/foo/bar', "./", true),
            array('/foo/bar', '/foo/baz', "../baz", true),
            array('/foo/bar/', '/foo/baz', "../baz", true),
            array('C:/foo/bar/', 'c:/foo/baz', "../baz", true),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('/foo/bin/run', '/bar/bin/run', "/bar/bin/run"),
            array('/foo/bin/run', '/bar/bin/run', "/bar/bin/run", true),
            array('c:/foo/bin/run', 'd:/bar/bin/run', "D:/bar/bin/run", true),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', "D:/vendor/acme/bin/run"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', "D:/vendor/acme/bin/run"),
            array('C:/Temp/test', 'C:\Temp', "./"),
            array('/tmp/test', '/tmp', "./"),
            array('C:/Temp/test/sub', 'C:\Temp', "../"),
            array('/tmp/test/sub', '/tmp', "../"),
            array('/tmp/test/sub', '/tmp', "../../", true),
            array('c:/tmp/test/sub', 'c:/tmp', "../../", true),
            array('/tmp', '/tmp/test', "test"),
            array('C:/Temp', 'C:\Temp\test', "test"),
            array('C:/Temp', 'c:\Temp\test', "test"),
            array('/tmp/test/./', '/tmp/test', './', true),
            array('/tmp/test/../vendor', '/tmp/test', '../test', true),
            array('/tmp/test/.././vendor', '/tmp/test', '../test', true),
            array('C:/Temp', 'c:\Temp\..\..\test', "../test", true),
            array('C:/Temp/../..', 'c:\Temp\..\..\test', "./test", true),
            array('C:/Temp/../..', 'D:\Temp\..\..\test', "D:/test", true),
            array('/tmp', '/tmp/../../test', '/test', true),
            array('/foo/bar', '/foo/bar_vendor', '../bar_vendor', true),
            array('/foo/bar_vendor', '/foo/bar', '../bar', true),
            array('/foo/bar_vendor', '/foo/bar/src', '../bar/src', true),
            array('/foo/bar_vendor/src2', '/foo/bar/src/lib', '../../bar/src/lib', true),
            array('C:/', 'C:/foo/bar/', "foo/bar", true),
        );
    }

    /**
     * @group GH-1339
     */
    public function testRemoveDirectoryPhp(): void
    {
        @mkdir($this->workingDir . "/level1/level2", 0777, true);
        file_put_contents($this->workingDir . "/level1/level2/hello.txt", "hello world");

        $fs = new Filesystem;
        $this->assertTrue($fs->removeDirectoryPhp($this->workingDir));
        $this->assertFileDoesNotExist($this->workingDir . "/level1/level2/hello.txt");
    }

    public function testFileSize(): void
    {
        file_put_contents($this->testFile, 'Hello');

        $fs = new Filesystem;
        $this->assertGreaterThanOrEqual(5, $fs->size($this->testFile));
    }

    public function testDirectorySize(): void
    {
        @mkdir($this->workingDir, 0777, true);
        file_put_contents($this->workingDir."/file1.txt", 'Hello');
        file_put_contents($this->workingDir."/file2.txt", 'World');

        $fs = new Filesystem;
        $this->assertGreaterThanOrEqual(10, $fs->size($this->workingDir));
    }

    /**
     * @dataProvider provideNormalizedPaths
     *
     * @param string $expected
     * @param string $actual
     */
    public function testNormalizePath(string $expected, string $actual): void
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->normalizePath($actual));
    }

    public function provideNormalizedPaths(): array
    {
        return array(
            array('../foo', '../foo'),
            array('C:/foo/bar', 'c:/foo//bar'),
            array('C:/foo/bar', 'C:/foo/./bar'),
            array('C:/foo/bar', 'C://foo//bar'),
            array('C:/foo/bar', 'C:///foo//bar'),
            array('C:/bar', 'C:/foo/../bar'),
            array('/bar', '/foo/../bar/'),
            array('phar://C:/Foo', 'phar://c:/Foo/Bar/..'),
            array('phar://C:/Foo', 'phar://c:///Foo/Bar/..'),
            array('phar://C:/', 'phar://c:/Foo/Bar/../../../..'),
            array('/', '/Foo/Bar/../../../..'),
            array('/', '/'),
            array('/', '//'),
            array('/', '///'),
            array('/Foo', '///Foo'),
            array('C:/', 'c:\\'),
            array('../src', 'Foo/Bar/../../../src'),
            array('C:../b', 'c:.\\..\\a\\..\\b'),
            array('phar://C:../Foo', 'phar://c:../Foo'),
            array('//foo/bar', '\\\\foo\\bar'),
        );
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
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($symlinked);
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
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($symlinkedTrailingSlash);
        $this->assertFileDoesNotExist($symlinked);
    }

    public function testJunctions(): void
    {
        @mkdir($this->workingDir . '/real/nesting/testing', 0777, true);
        $fs = new Filesystem();

        // Non-Windows systems do not support this and will return false on all tests, and an exception on creation
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->assertFalse($fs->isJunction($this->workingDir));
            $this->assertFalse($fs->removeJunction($this->workingDir));
            self::expectException('LogicException');
            self::expectExceptionMessage('not available on non-Windows platform');
        }

        $target = $this->workingDir . '/real/../real/nesting';
        $junction = $this->workingDir . '/junction';

        // Create and detect junction
        $fs->junction($target, $junction);
        $this->assertTrue($fs->isJunction($junction), $junction . ': is a junction');
        $this->assertFalse($fs->isJunction($target), $target . ': is not a junction');
        $this->assertTrue($fs->isJunction($target . '/../../junction'), $target . '/../../junction: is a junction');
        $this->assertFalse($fs->isJunction($junction . '/../real'), $junction . '/../real: is not a junction');
        $this->assertTrue($fs->isJunction($junction . '/../junction'), $junction . '/../junction: is a junction');

        // Remove junction
        $this->assertTrue(is_dir($junction), $junction . ' is a directory');
        $this->assertTrue($fs->removeJunction($junction), $junction . ' has been removed');
        $this->assertFalse(is_dir($junction), $junction . ' is not a directory');
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
        $this->assertTrue($result1, 'Copying directory failed.');
        $this->assertTrue(is_dir($this->workingDir . '/foop'), 'Not a directory: ' . $this->workingDir . '/foop');
        $this->assertTrue(is_dir($this->workingDir . '/foop/bar'), 'Not a directory: ' . $this->workingDir . '/foop/bar');
        $this->assertTrue(is_dir($this->workingDir . '/foop/baz'), 'Not a directory: ' . $this->workingDir . '/foop/baz');
        $this->assertTrue(is_file($this->workingDir . '/foop/foo.file'), 'Not a file: ' . $this->workingDir . '/foop/foo.file');
        $this->assertTrue(is_file($this->workingDir . '/foop/bar/foobar.file'), 'Not a file: ' . $this->workingDir . '/foop/bar/foobar.file');
        $this->assertTrue(is_file($this->workingDir . '/foop/baz/foobaz.file'), 'Not a file: ' . $this->workingDir . '/foop/baz/foobaz.file');

        $result2 = $fs->copy($this->testFile, $this->workingDir . '/testfile.file');
        $this->assertTrue($result2);
        $this->assertTrue(is_file($this->workingDir . '/testfile.file'));
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
        $this->assertFalse(is_file($this->testFile), 'Still a file: ' . $this->testFile);

        $fs->copyThenRemove($this->workingDir . '/foo', $this->workingDir . '/foop');
        $this->assertFalse(is_file($this->workingDir . '/foo/baz/foobaz.file'), 'Still a file: ' . $this->workingDir . '/foo/baz/foobaz.file');
        $this->assertFalse(is_file($this->workingDir . '/foo/bar/foobar.file'), 'Still a file: ' . $this->workingDir . '/foo/bar/foobar.file');
        $this->assertFalse(is_file($this->workingDir . '/foo/foo.file'), 'Still a file: ' . $this->workingDir . '/foo/foo.file');
        $this->assertFalse(is_dir($this->workingDir . '/foo/baz'), 'Still a directory: ' . $this->workingDir . '/foo/baz');
        $this->assertFalse(is_dir($this->workingDir . '/foo/bar'), 'Still a directory: ' . $this->workingDir . '/foo/bar');
        $this->assertFalse(is_dir($this->workingDir . '/foo'), 'Still a directory: ' . $this->workingDir . '/foo');
    }
}
