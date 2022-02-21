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

use Composer\IO\BufferIO;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\TestCase;

class HttpDownloaderTest extends TestCase
{
    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Config
     */
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
    public function testCaptureAuthenticationParamsFromUrl(): void
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

    public function testOutputWarnings(): void
    {
        $io = new BufferIO();
        HttpDownloader::outputWarnings($io, '$URL', array());
        $this->assertSame('', $io->getOutput());
        HttpDownloader::outputWarnings($io, '$URL', array(
            'warning' => 'old warning msg',
            'warning-versions' => '>=2.0',
            'info' => 'old info msg',
            'info-versions' => '>=2.0',
            'warnings' => array(
                array('message' => 'should not appear', 'versions' => '<2.2'),
                array('message' => 'visible warning', 'versions' => '>=2.2-dev'),
            ),
            'infos' => array(
                array('message' => 'should not appear', 'versions' => '<2.2'),
                array('message' => 'visible info', 'versions' => '>=2.2-dev'),
            ),
        ));

        // the <info> tag are consumed by the OutputFormatter, but not <warning> as that is not a default output format
        $this->assertSame(
            '<warning>Warning from $URL: old warning msg</warning>'.PHP_EOL.
            'Info from $URL: old info msg'.PHP_EOL.
            '<warning>Warning from $URL: visible warning</warning>'.PHP_EOL.
            'Info from $URL: visible info'.PHP_EOL,
            $io->getOutput()
        );
    }
}
