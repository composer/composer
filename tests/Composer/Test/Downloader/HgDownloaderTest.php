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
use Composer\TestCase;
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
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://mercurial.dev/l3l0/composer')));
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
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($this->workingDir.'/.hg');
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/l3l0/composer')));
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
        $downloader->update($packageMock, $packageMock, $this->workingDir);
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
        return Platform::isWindows() ? strtr($cmd, "'", '"') : $cmd;
    }
}
