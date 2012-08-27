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

class TarArchiverTest extends ArchiverTest
{
    public function testThis()
    {
        $this->setupGitRepo();
        $package = $this->setupPackage();
        $name = $this->getPackageFileName($package);

        $temp = sys_get_temp_dir();
        $tar = new TarArchiver($temp);
        $tar->dump($package);

        $dist = sprintf('%s/%s.tar',
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
        new TarArchiver("/totally-random-" . time());
    }
}
