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

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class PharArchiverTest extends ArchiverTest
{
    public function testTarArchive()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new PharArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'tar');
        $this->assertFileExists($target);

        unlink($target);
        $this->removeGitRepo();
    }

    public function testZipArchive()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.zip';

        // Test archive
        $archiver = new PharArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'zip');
        $this->assertFileExists($target);

        unlink($target);
        $this->removeGitRepo();
    }
}
