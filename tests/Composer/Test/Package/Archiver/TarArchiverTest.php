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

use Composer\Package\Archiver\TarArchiver;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class TarArchiverTest extends ArchiverTest
{
    public function testArchive()
    {
        $this->setupGitRepo();

        $package = $this->setupPackage();
        $target  = sys_get_temp_dir().'/composer_archiver_test.tar';

        // Test archive
        $archiver = new TarArchiver();
        $archiver->archive($package->getSourceUrl(), $target, 'tar');
        $this->assertFileExists($target);

        unlink($target);
        $this->removeGitRepo();
    }
}
