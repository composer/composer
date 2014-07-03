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

namespace Composer\Test\Package;

use Composer\Package\Manifest;
use Composer\Util\Filesystem;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

class ManifestTest extends \PHPUnit_Framework_TestCase
{
    protected $sources;
    protected $manifestAssembler;
    protected $fs;

    protected $manifest;

    protected function setUp()
    {
        $fs = new Filesystem;
        $this->fs = $fs;

        $this->sources = $fs->normalizePath(
            realpath(sys_get_temp_dir()).'/composer_archiver_test'.uniqid(mt_rand(), true)
        );

        $fileTree = array(
            'A/prefixA.foo',
            'A/prefixB.foo',
            'A/prefixC.foo',
            'A/prefixD.foo',
            'A/prefixE.foo',
            'A/prefixF.foo',
            'B/sub/prefixA.foo',
            'B/sub/prefixB.foo',
            'B/sub/prefixC.foo',
            'B/sub/prefixD.foo',
            'B/sub/prefixE.foo',
            'B/sub/prefixF.foo',
            'toplevelA.foo',
            'toplevelB.foo',
            'prefixA.foo',
            'prefixB.foo',
            'prefixC.foo',
            'prefixD.foo',
            'prefixE.foo',
            'prefixF.foo',
        );

        foreach ($fileTree as $relativePath) {
            $path = $this->sources.'/'.$relativePath;
            $fs->ensureDirectoryExists(dirname($path));
            file_put_contents($path, 'something');
        }
    }

    protected function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->sources);
        $this->manifest = null;
        $this->manifestAssembler = null;
    }

    /**
     * @group manifest
     */
    public function testManualExcludes()
    {
        $excludes = array(
            'prefixB.foo',
            '!/prefixB.foo',
            '/prefixA.foo',
            'prefixC.*',
            '!*/*/*/prefixC.foo'
        );

        $this->manifestAssembler = new Manifest($this->sources, $excludes);
        $this->manifest = $this->manifestAssembler->assemble();

        $expectedFiles = array_keys($this->manifest);

        $this->assertEquals(
            array(
                'A/prefixA.foo',
                'A/prefixD.foo',
                'A/prefixE.foo',
                'A/prefixF.foo',
                'B/sub/prefixA.foo',
                'B/sub/prefixC.foo',
                'B/sub/prefixD.foo',
                'B/sub/prefixE.foo',
                'B/sub/prefixF.foo',
                'prefixB.foo',
                'prefixD.foo',
                'prefixE.foo',
                'prefixF.foo',
                'toplevelA.foo',
                'toplevelB.foo',
            ),
            $expectedFiles
        );

        $expectedInfo = array(
            'hashes' => array(
                'sha256' => hash('sha256', 'something')
            ),
            'length' => 9
        );
        foreach ($this->manifest as $info) {
            $this->assertEquals($expectedInfo, $info); // since all info is identical in this test
        }
    }

}
