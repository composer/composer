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

use Composer\Package\Archiver\PharArchiver;

class PharArchiverTest extends ArchiverTest
{
    public function testTarArchive()
    {
        // Set up repository
        $this->setupDummyRepo();
        $package = $this->setupPackage();
        $target = $this->getUniqueTmpDirectory().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new PharArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'tar', array('foo/bar', 'baz', '!/foo/bar/baz'));
        $this->assertFileExists($target);

        $this->filesystem->removeDirectory(dirname($target));
    }

    public function testZipArchive()
    {
        // Set up repository
        $this->setupDummyRepo();
        $package = $this->setupPackage();
        $target = $this->getUniqueTmpDirectory().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new PharArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip');
        $this->assertFileExists($target);

        $this->filesystem->removeDirectory(dirname($target));
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
