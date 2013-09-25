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

namespace Composer\Repository\Pear;

use Composer\TestCase;
use Composer\Test\Mock\RemoteFilesystemMock;

class ChannelRest11ReaderTest extends TestCase
{
    public function testShouldBuildPackagesFromPearSchema()
    {
        $rfs = new RemoteFilesystemMock(array(
            'http://pear.1.1.net/channel.xml' => file_get_contents(__DIR__ . '/Fixtures/channel.1.1.xml'),
            'http://test.loc/rest11/c/categories.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/categories.xml'),
            'http://test.loc/rest11/c/Default/packagesinfo.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.1/packagesinfo.xml'),
        ));

        $reader = new \Composer\Repository\Pear\ChannelRest11Reader($rfs);

        /** @var $packages \Composer\Package\PackageInterface[] */
        $packages = $reader->read('http://test.loc/rest11');

        $this->assertCount(3, $packages);
        $this->assertEquals('HTTP_Client', $packages[0]->getPackageName());
        $this->assertEquals('HTTP_Request', $packages[1]->getPackageName());
    }
}
