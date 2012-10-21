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
        // Set up repository
        $this->setupMercurialRepo();
        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new MercurialArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip', 'default');
        $this->assertFileExists($target);

        unlink($target);
    }

    public function testTarArchive()
    {
        // Set up repository
        $this->setupMercurialRepo();
        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new MercurialArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'tar', 'default');
        $this->assertFileExists($target);

        unlink($target);
    }

    /**
     * Create local mercurial repository to run tests against!
     */
    protected function setupMercurialRepo()
    {
        $currentWorkDir = getcwd();
        chdir($this->testDir);

        $result = $this->process->execute('hg init -q');
        if ($result > 0) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not init: '.$this->process->getErrorOutput());
        }

        $result = file_put_contents('b', 'a');
        if (false === $result) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not save file.');
        }

        $result = $this->process->execute('hg add b && hg commit -m "commit b" --config ui.username=test -q');
        if ($result > 0) {
            chdir($currentWorkDir);
            throw new \RuntimeException('Could not commit: '.$this->process->getErrorOutput());
        }

        chdir($currentWorkDir);
    }

    protected function setupPackage()
    {
        $package = parent::setupPackage();
        $package->setSourceReference('default');
        $package->setSourceType('hg');

        return $package;
    }
}
