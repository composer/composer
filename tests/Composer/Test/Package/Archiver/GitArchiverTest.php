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

use Composer\Package\Archiver\GitArchiver;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class GitArchiverTest extends ArchiverTest
{
    public function testZipArchive()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new GitArchiver();
        $archiver->setFormat('zip');
        $archiver->setSourceRef('master');
        $archiver->archive($package->getSourceUrl(), $target);
        $this->assertFileExists($target);

        unlink($target);
        $this->removeGitRepo();
    }

    public function testTarArchive()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new GitArchiver();
        $archiver->setFormat('tar');
        $archiver->setSourceRef('master');
        $archiver->archive($package->getSourceUrl(), $target);
        $this->assertFileExists($target);

        unlink($target);
        $this->removeGitRepo();
    }
}
