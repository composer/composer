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
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class HgDownloaderTest extends TestCase
{
    /** @var string */
    private $workingDir;

    protected function setUp()
    {
        $this->workingDir = $this->getUniqueTmpDirectory();
    }

    protected function tearDown()
    {
        if (is_dir($this->workingDir)) {
            $fs = new Filesystem;
            $fs->removeDirectory($this->workingDir);
        }
    }

    protected function getDownloaderMock($io = null, $config = null, $executor = null, $filesystem = null)
    {
        $io = $io ?: $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $config = $config ?: $this->getMockBuilder('Composer\Config')->getMock();
        $executor = $executor ?: $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $filesystem = $filesystem ?: $this->getMockBuilder('Composer\Util\Filesystem')->getMock();

        return new HgDownloader($io, $config, $executor, $filesystem);
    }

    public function testDownloadForPackageWithoutSourceReference()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        $this->setExpectedException('InvalidArgumentException');

        $downloader = $this->getDownloaderMock();
        $downloader->install($packageMock, '/path');
    }

    public function testDownload()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->once())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://mercurial.dev/l3l0/composer')));
        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();

        $expectedGitCommand = $this->getCmd('hg clone -- \'https://mercurial.dev/l3l0/composer\' \'composerPath\'');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $expectedGitCommand = $this->getCmd('hg up -- \'ref\'');
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, null, $processExecutor);
        $downloader->install($packageMock, 'composerPath');
    }

    public function testUpdateforPackageWithoutSourceReference()
    {
        $initialPackageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $sourcePackageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $sourcePackageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        $this->setExpectedException('InvalidArgumentException');

        $downloader = $this->getDownloaderMock();
        $downloader->prepare('update', $sourcePackageMock, '/path', $initialPackageMock);
        $downloader->update($initialPackageMock, $sourcePackageMock, '/path');
        $downloader->cleanup('update', $sourcePackageMock, '/path', $initialPackageMock);
    }

    public function testUpdate()
    {
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($this->workingDir.'/.hg');
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/l3l0/composer')));
        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();

        $expectedHgCommand = $this->getCmd("hg st");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedHgCommand))
            ->will($this->returnValue(0));
        $expectedHgCommand = $this->getCmd("hg pull -- 'https://github.com/l3l0/composer' && hg up -- 'ref'");
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedHgCommand))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, null, $processExecutor);
        $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
        $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);
    }

    public function testRemove()
    {
        $expectedResetCommand = $this->getCmd('cd \'composerPath\' && hg st');

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->any())
            ->method('execute')
            ->with($this->equalTo($expectedResetCommand));
        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $filesystem->expects($this->once())
            ->method('removeDirectoryAsync')
            ->with($this->equalTo('composerPath'))
            ->will($this->returnValue(\React\Promise\resolve(true)));

        $downloader = $this->getDownloaderMock(null, null, $processExecutor, $filesystem);
        $downloader->prepare('uninstall', $packageMock, 'composerPath');
        $downloader->remove($packageMock, 'composerPath');
        $downloader->cleanup('uninstall', $packageMock, 'composerPath');
    }

    public function testGetInstallationSource()
    {
        $downloader = $this->getDownloaderMock(null);

        $this->assertEquals('source', $downloader->getInstallationSource());
    }

    private function getCmd($cmd)
    {
        return Platform::isWindows() ? strtr($cmd, "'", '"') : $cmd;
    }
}
