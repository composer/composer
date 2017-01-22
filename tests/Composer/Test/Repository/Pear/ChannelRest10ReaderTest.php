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

namespace Composer\Test\Repository\Pear;

use Composer\TestCase;
use Composer\Test\Mock\RemoteFilesystemMock;

class ChannelRest10ReaderTest extends TestCase
{
    public function testShouldBuildPackagesFromPearSchema()
    {
        $rfs = new RemoteFilesystemMock(array(
            'http://test.loc/rest10/p/packages.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/packages.xml'),
            'http://test.loc/rest10/p/http_client/info.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_client_info.xml'),
            'http://test.loc/rest10/r/http_client/allreleases.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_client_allreleases.xml'),
            'http://test.loc/rest10/r/http_client/deps.1.2.1.txt' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_client_deps.1.2.1.txt'),
            'http://test.loc/rest10/p/http_request/info.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_request_info.xml'),
            'http://test.loc/rest10/r/http_request/allreleases.xml' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_request_allreleases.xml'),
            'http://test.loc/rest10/r/http_request/deps.1.4.0.txt' => file_get_contents(__DIR__ . '/Fixtures/Rest1.0/http_request_deps.1.4.0.txt'),
        ));

        $reader = new \Composer\Repository\Pear\ChannelRest10Reader($rfs);

        /** @var $packages \Composer\Package\PackageInterface[] */
        $packages = $reader->read('http://test.loc/rest10');

        $this->assertCount(2, $packages);
        $this->assertEquals('HTTP_Client', $packages[0]->getPackageName());
        $this->assertEquals('HTTP_Request', $packages[1]->getPackageName());
    }
}
