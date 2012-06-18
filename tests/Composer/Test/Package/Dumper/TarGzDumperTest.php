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

use Composer\Package\Dumper\TarGzDumper;
use Composer\Package\MemoryPackage;

class TarGzDumperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @todo Replace with local git repo to run offline.
     */
    public function testThis()
    {
        $package = new MemoryPackage('lagged/Lagged_Session_SaveHandler_Memcache', '0.5.0', '0.5.0');
        $package->setSourceUrl('git://github.com/lagged/Lagged_Session_SaveHandler_Memcache.git');
        $package->setSourceReference('0.5.0');
        $package->setSourceType('git');

        $temp = sys_get_temp_dir();

        $targz = new TarGzDumper($temp);
        $targz->dump($package);

        $name = preg_replace('#[^a-z0-9_-]#', '-', $package->getUniqueName());

        $dist = sprintf('%s/%s.tar.gz',
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
        new TarGzDumper("/totally-random-" . time());
    }
}
