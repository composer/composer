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

namespace Composer\Test\Util;

use Composer\Util\HttpDownloader;
use PHPUnit\Framework\TestCase;

class HttpDownloaderTest extends TestCase
{
    private function getConfigMock()
    {
        $config = $this->getMockBuilder('Composer\Config')->getMock();
        $config->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(function ($key) {
                if ($key === 'github-domains' || $key === 'gitlab-domains') {
                    return array();
                }
            }));

        return $config;
    }

    /**
     * @group slow
     */
    public function testCaptureAuthenticationParamsFromUrl()
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->equalTo('github.com'), $this->equalTo('user'), $this->equalTo('pass'));

        $fs = new HttpDownloader($io, $this->getConfigMock());
        try {
            $fs->get('https://user:pass@github.com/composer/composer/404');
        } catch (\Composer\Downloader\TransportException $e) {
            $this->assertNotEquals(200, $e->getCode());
        }
    }
}
