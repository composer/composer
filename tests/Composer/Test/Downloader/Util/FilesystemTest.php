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

namespace Composer\Test\Repository;

use Composer\Downloader\Util\Filesystem;
use Composer\Test\TestCase;

class FilesystemTest extends TestCase
{
    /**
     * @dataProvider providePathCouplesAsCode
     */
    public function testFindShortestPathCode($a, $b, $expected)
    {
        $fs = new Filesystem;
        $this->assertEquals($expected, $fs->findShortestPathCode($a, $b));
    }

    public function providePathCouplesAsCode()
    {
        return array(
            array('/foo/bar', '/foo/bar', "__FILE__"),
            array('/foo/bar', '/foo/baz', "__DIR__.'/baz'"),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('/foo/bin/run', '/bar/bin/run', "'/bar/bin/run'"),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', "dirname(__DIR__).'/vendor/acme/bin/run'"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', "'d:/vendor/acme/bin/run'"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', "'d:/vendor/acme/bin/run'"),
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
            array('/foo/bin/run', '/foo/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('/foo/bin/run', '/bar/bin/run', "/bar/bin/run"),
            array('c:/bin/run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:\\bin\\run', 'c:/vendor/acme/bin/run', "../vendor/acme/bin/run"),
            array('c:/bin/run', 'd:/vendor/acme/bin/run', "d:/vendor/acme/bin/run"),
            array('c:\\bin\\run', 'd:/vendor/acme/bin/run', "d:/vendor/acme/bin/run"),
        );
    }
}
