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

use Composer\Package\Archiver\MercurialArchiver;
use Composer\Package\Package;

/**
 * @author Matthieu Moquet <matthieu@moquet.net>
 * @author Till Klampaeckel <till@php.net>
 */
class MercurialArchiverTest extends ArchiverTest
{
    public function testZipArchive()
    {
        $this->setupMercurialRepo();

        $package = $this->setupMercurialPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new MercurialArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip', 'default');
        $this->assertFileExists($target);

        unlink($target);
        $this->removeMercurialRepo();
    }

    public function testTarArchive()
    {
        $this->setupMercurialRepo();

        $package = $this->setupMercurialPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new MercurialArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'tar', 'default');
        $this->assertFileExists($target);

        unlink($target);
        $this->removeMercurialRepo();
    }

    /**
     * Create local git repository to run tests against!
     */
    protected function setupMercurialRepo()
    {
        $this->filesystem->removeDirectory($this->testDir);
        $this->filesystem->ensureDirectoryExists($this->testDir);

        $currentWorkDir = getcwd();
        chdir($this->testDir);

        $result = $this->process->execute('hg init -q');
        if ($result > 0) {
            throw new \RuntimeException('Could not init: '.$this->process->getErrorOutput());
        }

        $result = file_put_contents('b', 'a');
        if (false === $result) {
            throw new \RuntimeException('Could not save file.');
        }

        $result = $this->process->execute('hg add b && hg commit -m "commit b" --config ui.username=test -q');
        if ($result > 0) {
            throw new \RuntimeException('Could not commit: '.$this->process->getErrorOutput());
        }

        chdir($currentWorkDir);
    }

    protected function removeMercurialRepo()
    {
        $this->filesystem->removeDirectory($this->testDir);
    }

    protected function setupMercurialPackage()
    {
        $package = new Package('archivertest/archivertest', 'master', 'master');
        $package->setSourceUrl(realpath($this->testDir));
        $package->setSourceReference('default');
        $package->setSourceType('hg');

        return $package;
    }
}
