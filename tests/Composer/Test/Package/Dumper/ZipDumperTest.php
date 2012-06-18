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

namespace Composer\Test\Package\Dumper;

use Composer\Package\Dumper\ZipDumper;

class ZipDumperTest extends DumperTest
{
    public function testThis()
    {
        $retu = $this->getPackageName();
        $package = $retu['package'];
        $name = $retu['name'];

        $temp = sys_get_temp_dir();
        $zip = new ZipDumper($temp);
        $zip->dump($package);

        $dist = sprintf('%s/%s.zip',
            $temp, $name
        );
        $this->assertFileExists($dist);
        unlink($dist);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testException()
    {
        new ZipDumper("/totally-random-" . time());
    }
}
