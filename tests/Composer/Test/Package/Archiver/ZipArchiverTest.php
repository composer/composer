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

namespace Composer\Test\Package\Archiver;

use Composer\Package\Archiver\ZipArchiver;

class ZipArchiverTest extends ArchiverTest
{
    public function testZipArchive()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('Cannot run ZipArchiverTest, missing class "ZipArchive".');
        }

        // Set up repository
        $this->setupDummyRepo();
        $package = $this->setupPackage();
        $target = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new ZipArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip');
        $this->assertFileExists($target);

        unlink($target);
    }

    /**
     * Create a local dummy repository to run tests against!
     */
    protected function setupDummyRepo()
    {
        $currentWorkDir = getcwd();
        chdir($this->testDir);

        $this->writeFile('file.txt', 'content', $currentWorkDir);
        $this->writeFile('foo/bar/baz', 'content', $currentWorkDir);
        $this->writeFile('foo/bar/ignoreme', 'content', $currentWorkDir);
        $this->writeFile('x/baz', 'content', $currentWorkDir);
        $this->writeFile('x/includeme', 'content', $currentWorkDir);

        chdir($currentWorkDir);
    }

    protected function writeFile($path, $content, $currentWorkDir)
    {
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $result = file_put_contents($path, 'a');
        if (false === $result) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not save file.');
        }
    }
}
