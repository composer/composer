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
use Composer\Test\TestCase;

class FilesystemTest extends TestCase
{
    /**
     * @dataProvider providePathCouplesAsCode
     */
    public function testFindShortestPathCode($a, $b, $directory, $expected)
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->findShortestPathCode($a, $b, $directory));
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
        );
    }

    /**
     * @dataProvider providePathCouples
     */
    public function testFindShortestPath($a, $b, $expected)
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->findShortestPath($a, $b));
    }

    public function providePathCouples()
    {
        return array(
            array('/foo/bar', '/foo/bar', "./bar"),
            array('/foo/bar', '/foo/baz', "./baz"),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('/foo/bin/run', '/bar/bin/run', "/bar/bin/run"),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', "d:/vendor/acme/bin/run"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', "d:/vendor/acme/bin/run"),
            array('C:/Temp/test', 'C:\Temp', "./"),
            array('/tmp/test', '/tmp', "./"),
            array('C:/Temp/test/sub', 'C:\Temp', "../"),
            array('/tmp/test/sub', '/tmp', "../"),
            array('/tmp', '/tmp/test', "test"),
            array('C:/Temp', 'C:\Temp\test', "test"),
            array('C:/Temp', 'c:\Temp\test', "test"),
        );
    }
}
