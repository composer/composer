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
    public function testThis()
    {
        $this->setupGitRepo();
        $package = $this->setupPackage();
        $name = $this->getPackageFileName($package);

        $temp = sys_get_temp_dir();
        $zip = new ZipArchiver($temp);
        $zip->dump($package);

        $dist = sprintf('%s/%s.zip',
            $temp, $name
        );
        $this->assertFileExists($dist);
        unlink($dist);
        $this->removeGitRepo();
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testException()
    {
        new ZipArchiver("/totally-random-" . time());
    }
}
