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

use Composer\Downloader\GitDownloader;

class GitDownloaderTest extends \PHPUnit_Framework_TestCase
{
    protected function getDownloaderMock($io = null, $executor = null, $filesystem = null)
    {
        $io = $io ?: $this->getMock('Composer\IO\IOInterface');
        $executor = $executor ?: $this->getMock('Composer\Util\ProcessExecutor');
        $filesystem = $filesystem ?: $this->getMock('Composer\Util\Filesystem');

        return new GitDownloader($io, $executor, $filesystem);
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
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->getCmd("git clone 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git checkout 'ref' && git reset --hard 'ref'");
        $processExecutor->expects($this->once())
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    public function testDownloadUsesVariousProtocolsAndSetsPushUrlForGithub()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->getCmd("git clone 'git://github.com/composer/composer' 'composerPath' && cd 'composerPath' && git checkout 'ref' && git reset --hard 'ref'");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(1));

        $expectedGitCommand = $this->getCmd("git clone 'https://github.com/composer/composer' 'composerPath' && cd 'composerPath' && git checkout 'ref' && git reset --hard 'ref'");
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(1));

        $expectedGitCommand = $this->getCmd("git clone 'http://github.com/composer/composer' 'composerPath' && cd 'composerPath' && git checkout 'ref' && git reset --hard 'ref'");
        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $expectedGitCommand = $this->getCmd("cd 'composerPath' && git remote set-url --push origin 'git@github.com:composer/composer.git'");
        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testDownloadThrowsRuntimeExceptionIfGitCommandFails()
    {
        $expectedGitCommand = $this->getCmd("git clone 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git checkout 'ref' && git reset --hard 'ref'");
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->once())
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(1));

        $downloader = $this->getDownloaderMock(null, $processExecutor);
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
        $expectedGitUpdateCommand = $this->getCmd("cd 'composerPath' && git remote set-url origin 'git://github.com/composer/composer' && git fetch origin && git fetch --tags origin && git checkout 'ref' && git reset --hard 'ref'");
        $expectedGitResetCommand = $this->getCmd("cd 'composerPath' && git status --porcelain");

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitResetCommand))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedGitUpdateCommand))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, $processExecutor);
        $downloader->update($packageMock, $packageMock, 'composerPath');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testUpdateThrowsRuntimeExceptionIfGitCommandFails()
    {
        $expectedGitUpdateCommand = $this->getCmd("cd 'composerPath' && git remote set-url origin 'git://github.com/composer/composer' && git fetch origin && git fetch --tags origin && git checkout 'ref' && git reset --hard 'ref'");
        $expectedGitResetCommand = $this->getCmd("cd 'composerPath' && git status --porcelain");

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitResetCommand))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedGitUpdateCommand))
            ->will($this->returnValue(1));

        $downloader = $this->getDownloaderMock(null, $processExecutor);
        $downloader->update($packageMock, $packageMock, 'composerPath');
    }

    public function testRemove()
    {
        $expectedGitResetCommand = $this->getCmd("cd 'composerPath' && git status --porcelain");

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->any())
            ->method('execute')
            ->with($this->equalTo($expectedGitResetCommand))
            ->will($this->returnValue(0));
        $filesystem = $this->getMock('Composer\Util\Filesystem');
        $filesystem->expects($this->any())
            ->method('removeDirectory')
            ->with($this->equalTo('composerPath'));

        $downloader = $this->getDownloaderMock(null, $processExecutor, $filesystem);
        $downloader->remove($packageMock, 'composerPath');
    }

    public function testGetInstallationSource()
    {
        $downloader = $this->getDownloaderMock();

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
