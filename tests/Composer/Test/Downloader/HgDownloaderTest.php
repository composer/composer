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

class HgDownloaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testDownloadForPackageWithoutSourceReference()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        $downloader = new HgDownloader($this->getMock('Composer\IO\IOInterface'));
        $downloader->download($packageMock, '/path');
    }

    public function testDownload()
    {
        $expectedGitCommand = 'hg clone \'https://mercurial.dev/l3l0/composer\' \'composerPath\' && cd \'composerPath\' && hg up \'ref\'';
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->once())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://mercurial.dev/l3l0/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->once())
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand));

        $downloader = new HgDownloader($this->getMock('Composer\IO\IOInterface'), $processExecutor);
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

        $downloader = new HgDownloader($this->getMock('Composer\IO\IOInterface'));
        $downloader->update($initialPackageMock, $sourcePackageMock, '/path');
    }

    public function testUpdate()
    {
        $expectedUpdateCommand = 'cd \'composerPath\' && hg pull && hg up \'ref\'';
        $expectedResetCommand = 'cd \'composerPath\' && hg st';

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/l3l0/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedResetCommand));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedUpdateCommand));

        $downloader = new HgDownloader($this->getMock('Composer\IO\IOInterface'), $processExecutor);
        $downloader->update($packageMock, $packageMock, 'composerPath');
    }

    public function testRemove()
    {
        $expectedResetCommand = 'cd \'composerPath\' && hg st';

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->any())
            ->method('execute')
            ->with($this->equalTo($expectedResetCommand));

        $downloader = new HgDownloader($this->getMock('Composer\IO\IOInterface'), $processExecutor);
        $downloader->remove($packageMock, 'composerPath');
    }

    public function testGetInstallationSource()
    {
        $downloader = new HgDownloader($this->getMock('Composer\IO\IOInterface'));
        
        $this->assertEquals('source', $downloader->getInstallationSource());
    }
}
