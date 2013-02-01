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

namespace Composer\Test\Downloader;

use Composer\Downloader\SvnDownloader;

class SvnDownloaderTest extends \PHPUnit_Framework_TestCase
{
    protected function getDownloaderMock($io = null, $config = null, $executor = null, $filesystem = null)
    {
        $io = $io ?: $this->getMock('Composer\IO\IOInterface');
        $config = $config ?: $this->getMock('Composer\Config');
        $executor = $executor ?: $this->getMock('Composer\Util\ProcessExecutor');
        $filesystem = $filesystem ?: $this->getMock('Composer\Util\Filesystem');

        return new SvnDownloader($io, $config, $executor, $filesystem);
    }

    public function testRewriteUrlForDownload()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())->method('getSourceReference')->will($this->returnValue('ref'));
        $packageMock->expects($this->any())->method('getSourceUrl')->will($this->returnValue('http://example.com/foo'));

        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $expectedCommand = $this->getCmd("svn co --non-interactive  'http://proxy:4545/foo/ref' 'composerPath'");
        $processExecutor->expects($this->at(0))->method('execute')->with($this->equalTo($expectedCommand))->will($this->returnValue(0));

        $config = $this->getMock('Composer\Config');
        $config->expects($this->any())
            ->method('get')->with('url-rewrite-rules')
            ->will($this->returnValue(array('^http://example.com/(.+)$' => 'http://proxy:4545/\\1')));

        $downloader = $this->getDownloaderMock(null, $config, $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    public function testRewriteUrlForUpdate()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())->method('getSourceReference')->will($this->returnValue('ref'));
        $packageMock->expects($this->any())->method('getSourceUrl')->will($this->returnValue('http://example.com/foo'));

        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $expectedCommand = $this->getCmd("svn switch --non-interactive  'http://proxy:4545/foo/ref'");
        $processExecutor->expects($this->at(0))->method('execute')->with($this->equalTo($expectedCommand))->will($this->returnValue(0));

        $config = $this->getMock('Composer\Config');
        $config->expects($this->any())
            ->method('get')->with('url-rewrite-rules')
            ->will($this->returnValue(array('^http://example.com/(.+)$' => 'http://proxy:4545/\\1')));

        $downloader = $this->getDownloaderMock(null, $config, $processExecutor);
        $downloader->update($packageMock, $packageMock, 'composerPath');
    }

    private function getCmd($cmd)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            return strtr($cmd, "'", '"');
        }

        return $cmd;
    }
}
