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

use Composer\Downloader\HgDownloader;
use Composer\Util\Filesystem;

class HgDownloaderTest extends \PHPUnit_Framework_TestCase
{
    protected function getDownloaderMock($io = null, $config = null, $executor = null, $filesystem = null)
    {
        $io = $io ?: $this->getMock('Composer\IO\IOInterface');
        $config = $config ?: $this->getMock('Composer\Config');
        $executor = $executor ?: $this->getMock('Composer\Util\ProcessExecutor');
        $filesystem = $filesystem ?: $this->getMock('Composer\Util\Filesystem');

        return new HgDownloader($io, $config, $executor, $filesystem);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testDownloadForPackageWithoutSourceReference()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        $downloader = $this->getDownloaderMock();
        $downloader->download($packageMock, '/path');
    }

    public function testDownload()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->once())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://mercurial.dev/l3l0/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->getCmd('hg clone \'https://mercurial.dev/l3l0/composer\' \'composerPath\'');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $expectedGitCommand = $this->getCmd('hg up \'ref\'');
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, null, $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testUpdateforPackageWithoutSourceReference()
    {
        $initialPackageMock = $this->getMock('Composer\Package\PackageInterface');
        $sourcePackageMock = $this->getMock('Composer\Package\PackageInterface');
        $sourcePackageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        $downloader = $this->getDownloaderMock();
        $downloader->update($initialPackageMock, $sourcePackageMock, '/path');
    }

    public function testUpdate()
    {
        $tmpDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cmptest-'.md5(uniqid('', true));
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($tmpDir.'/.hg');
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/l3l0/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedHgCommand = $this->getCmd("hg st");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedHgCommand))
            ->will($this->returnValue(0));
        $expectedHgCommand = $this->getCmd("hg pull 'https://github.com/l3l0/composer' && hg up 'ref'");
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedHgCommand))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, null, $processExecutor);
        $downloader->update($packageMock, $packageMock, $tmpDir);
    }

    public function testRemove()
    {
        $expectedResetCommand = $this->getCmd('cd \'composerPath\' && hg st');

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->any())
            ->method('execute')
            ->with($this->equalTo($expectedResetCommand));
        $filesystem = $this->getMock('Composer\Util\Filesystem');
        $filesystem->expects($this->any())
            ->method('removeDirectory')
            ->with($this->equalTo('composerPath'))
            ->will($this->returnValue(true));

        $downloader = $this->getDownloaderMock(null, null, $processExecutor, $filesystem);
        $downloader->remove($packageMock, 'composerPath');
    }

    public function testGetInstallationSource()
    {
        $downloader = $this->getDownloaderMock(null);

        $this->assertEquals('source', $downloader->getInstallationSource());
    }

    private function getCmd($cmd)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            return strtr($cmd, "'", '"');
        }

        return $cmd;
    }
}
