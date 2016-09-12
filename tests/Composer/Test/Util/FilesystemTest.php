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

namespace Composer\Test\Util;

use Composer\Util\Filesystem;
use Composer\TestCase;

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

    public function setUp()
    {
        $this->fs = new Filesystem;
        $this->workingDir = $this->getUniqueTmpDirectory();
        $this->testFile = $this->getUniqueTmpDirectory() . '/composer_test_file';
    }

    public function tearDown()
    {
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
    public function testFindShortestPathCode($a, $b, $directory, $expected, $static = false)
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->findShortestPathCode($a, $b, $directory, $static));
    }

    public function providePathCouplesAsCode()
    {
        return array(
            array('/foo/bar', '/foo/bar', false, "__FILE__"),
            array('/foo/bar', '/foo/baz', false, "__DIR__.'/baz'"),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('/foo/bin/run', '/bar/bin/run', false, "'/bar/bin/run'"),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', false, "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', false, "'d:/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', false, "'d:/vendor/acme/bin/run'"),
            array('/foo/bar', '/foo/bar', true, "__DIR__"),
            array('/foo/bar/', '/foo/bar', true, "__DIR__"),
            array('/foo/bar', '/foo/baz', true, "dirname(__DIR__).'/baz'"),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"),
            array('/foo/bin/run', '/bar/bin/run', true, "'/bar/bin/run'"),
            array('/bin/run', '/bin/run', true, "__DIR__"),
            array('c:/bin/run', 'c:\\bin/run', true, "__DIR__"),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', true, "dirname(dirname(__DIR__)).'/vendor/acme/bin/run'"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', true, "'d:/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', true, "'d:/vendor/acme/bin/run'"),
            array('C:/Temp/test', 'C:\Temp', true, "dirname(__DIR__)"),
            array('C:/Temp', 'C:\Temp\test', true, "__DIR__ . '/test'"),
            array('/tmp/test', '/tmp', true, "dirname(__DIR__)"),
            array('/tmp', '/tmp/test', true, "__DIR__ . '/test'"),
            array('C:/Temp', 'c:\Temp\test', true, "__DIR__ . '/test'"),
            array('/tmp/test/./', '/tmp/test/', true, '__DIR__'),
            array('/tmp/test/../vendor', '/tmp/test', true, "dirname(__DIR__).'/test'"),
            array('/tmp/test/.././vendor', '/tmp/test', true, "dirname(__DIR__).'/test'"),
            array('C:/Temp', 'c:\Temp\..\..\test', true, "dirname(__DIR__).'/test'"),
            array('C:/Temp/../..', 'd:\Temp\..\..\test', true, "'d:/test'"),
            array('/foo/bar', '/foo/bar_vendor', true, "dirname(__DIR__).'/bar_vendor'"),
            array('/foo/bar_vendor', '/foo/bar', true, "dirname(__DIR__).'/bar'"),
            array('/foo/bar_vendor', '/foo/bar/src', true, "dirname(__DIR__).'/bar/src'"),
            array('/foo/bar_vendor/src2', '/foo/bar/src/lib', true, "dirname(dirname(__DIR__)).'/bar/src/lib'"),

            // static use case
            array('/tmp/test/../vendor', '/tmp/test', true, "__DIR__ . '/..'.'/test'", true),
            array('/tmp/test/.././vendor', '/tmp/test', true, "__DIR__ . '/..'.'/test'", true),
            array('C:/Temp', 'c:\Temp\..\..\test', true, "__DIR__ . '/..'.'/test'", true),
            array('C:/Temp/../..', 'd:\Temp\..\..\test', true, "'d:/test'", true),
            array('/foo/bar', '/foo/bar_vendor', true, "__DIR__ . '/..'.'/bar_vendor'", true),
            array('/foo/bar_vendor', '/foo/bar', true, "__DIR__ . '/..'.'/bar'", true),
            array('/foo/bar_vendor', '/foo/bar/src', true, "__DIR__ . '/..'.'/bar/src'", true),
            array('/foo/bar_vendor/src2', '/foo/bar/src/lib', true, "__DIR__ . '/../..'.'/bar/src/lib'", true),
        );
    }

    /**
     * @dataProvider providePathCouples
     */
    public function testFindShortestPath($a, $b, $expected, $directory = false)
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->findShortestPath($a, $b, $directory));
    }

    public function providePathCouples()
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
            array('c:/foo/bin/run', 'd:/bar/bin/run', "d:/bar/bin/run", true),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', "d:/vendor/acme/bin/run"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', "d:/vendor/acme/bin/run"),
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
            array('C:/Temp/../..', 'D:\Temp\..\..\test', "d:/test", true),
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
    public function testRemoveDirectoryPhp()
    {
        @mkdir($this->workingDir . "/level1/level2", 0777, true);
        file_put_contents($this->workingDir . "/level1/level2/hello.txt", "hello world");

        $fs = new Filesystem;
        $this->assertTrue($fs->removeDirectoryPhp($this->workingDir));
        $this->assertFalse(file_exists($this->workingDir . "/level1/level2/hello.txt"));
    }

    public function testFileSize()
    {
        file_put_contents($this->testFile, 'Hello');

        $fs = new Filesystem;
        $this->assertGreaterThanOrEqual(5, $fs->size($this->testFile));
    }

    public function testDirectorySize()
    {
        @mkdir($this->workingDir, 0777, true);
        file_put_contents($this->workingDir."/file1.txt", 'Hello');
        file_put_contents($this->workingDir."/file2.txt", 'World');

        $fs = new Filesystem;
        $this->assertGreaterThanOrEqual(10, $fs->size($this->workingDir));
    }

    /**
     * @dataProvider provideNormalizedPaths
     */
    public function testNormalizePath($expected, $actual)
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->normalizePath($actual));
    }

    public function provideNormalizedPaths()
    {
        return array(
            array('../foo', '../foo'),
            array('c:/foo/bar', 'c:/foo//bar'),
            array('C:/foo/bar', 'C:/foo/./bar'),
            array('C:/foo/bar', 'C://foo//bar'),
            array('C:/foo/bar', 'C:///foo//bar'),
            array('C:/bar', 'C:/foo/../bar'),
            array('/bar', '/foo/../bar/'),
            array('phar://c:/Foo', 'phar://c:/Foo/Bar/..'),
            array('phar://c:/Foo', 'phar://c:///Foo/Bar/..'),
            array('phar://c:/', 'phar://c:/Foo/Bar/../../../..'),
            array('/', '/Foo/Bar/../../../..'),
            array('/', '/'),
            array('/', '//'),
            array('/', '///'),
            array('/Foo', '///Foo'),
            array('c:/', 'c:\\'),
            array('../src', 'Foo/Bar/../../../src'),
            array('c:../b', 'c:.\\..\\a\\..\\b'),
            array('phar://c:../Foo', 'phar://c:../Foo'),
        );
    }

    /**
     * @link https://github.com/composer/composer/issues/3157
     * @requires function symlink
     */
    public function testUnlinkSymlinkedDirectory()
    {
        $basepath  = $this->workingDir;
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

        $fs     = new Filesystem();
        $result = $fs->unlink($symlinked);
        $this->assertTrue($result);
        $this->assertFalse(file_exists($symlinked));
    }

    /**
     * @link https://github.com/composer/composer/issues/3144
     * @requires function symlink
     */
    public function testRemoveSymlinkedDirectoryWithTrailingSlash()
    {
        @mkdir($this->workingDir . "/real", 0777, true);
        touch($this->workingDir . "/real/FILE");
        $symlinked              = $this->workingDir . "/linked";
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
        $this->assertFalse(file_exists($symlinkedTrailingSlash));
        $this->assertFalse(file_exists($symlinked));
    }

    public function testJunctions()
    {
        @mkdir($this->workingDir . '/real/nesting/testing', 0777, true);
        $fs = new Filesystem();

        // Non-Windows systems do not support this and will return false on all tests, and an exception on creation
        if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->assertFalse($fs->isJunction($this->workingDir));
            $this->assertFalse($fs->removeJunction($this->workingDir));
            $this->setExpectedException('LogicException', 'not available on non-Windows platform');
        }

        $target = $this->workingDir . '/real/../real/nesting';
        $junction = $this->workingDir . '/junction';

        // Create and detect junction
        $fs->junction($target, $junction);
        $this->assertTrue($fs->isJunction($junction));
        $this->assertFalse($fs->isJunction($target));
        $this->assertTrue($fs->isJunction($target . '/../../junction'));
        $this->assertFalse($fs->isJunction($junction . '/../real'));
        $this->assertTrue($fs->isJunction($junction . '/../junction'));

        // Remove junction
        $this->assertTrue(is_dir($junction));
        $this->assertTrue($fs->removeJunction($junction));
        $this->assertFalse(is_dir($junction));
    }
}
