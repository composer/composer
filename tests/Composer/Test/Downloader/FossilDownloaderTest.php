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

use Composer\Downloader\FossilDownloader;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Test\Mock\ProcessExecutorMock;

class FossilDownloaderTest extends TestCase
{
    /** @var string */
    private $workingDir;

    protected function setUp(): void
    {
        $this->workingDir = $this->getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->workingDir)) {
            $fs = new Filesystem;
            $fs->removeDirectory($this->workingDir);
        }
    }

    /**
     * @param \Composer\IO\IOInterface $io
     * @param \Composer\Config $config
     * @param \Composer\Test\Mock\ProcessExecutorMock $executor
     * @param \Composer\Util\Filesystem $filesystem
     * @return FossilDownloader
     */
    protected function getDownloaderMock($io = null, $config = null, $executor = null, $filesystem = null)
    {
        $io = $io ?: $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $config = $config ?: $this->getMockBuilder('Composer\Config')->getMock();
        $executor = $executor ?: $this->getProcessExecutorMock();
        $filesystem = $filesystem ?: $this->getMockBuilder('Composer\Util\Filesystem')->getMock();

        return new FossilDownloader($io, $config, $executor, $filesystem);
    }

    public function testInstallForPackageWithoutSourceReference()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        $this->setExpectedException('InvalidArgumentException');

        $downloader = $this->getDownloaderMock();
        $downloader->install($packageMock, '/path');
    }

    public function testInstall()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('trunk'));
        $packageMock->expects($this->once())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('http://fossil.kd2.org/kd2fw/')));

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->getCmd('fossil clone -- \'http://fossil.kd2.org/kd2fw/\' \'repo.fossil\''),
            $this->getCmd('fossil open --nested -- \'repo.fossil\''),
            $this->getCmd('fossil update -- \'trunk\''),
        ), true);

        $downloader = $this->getDownloaderMock(null, null, $process);
        $downloader->install($packageMock, 'repo');
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
        // Ensure file exists
        $file = $this->workingDir . '/.fslckout';

        if (!file_exists($file)) {
            touch($file);
        }

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('trunk'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('http://fossil.kd2.org/kd2fw/')));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->getCmd("fossil changes"),
            $this->getCmd("fossil pull && fossil up 'trunk'"),
        ), true);

        $downloader = $this->getDownloaderMock(null, null, $process);
        $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
        $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);
    }

    public function testRemove()
    {
        // Ensure file exists
        $file = $this->workingDir . '/.fslckout';
        touch($file);

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->getCmd('fossil changes'),
        ), true);

        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $filesystem->expects($this->once())
            ->method('removeDirectoryAsync')
            ->with($this->equalTo($this->workingDir))
            ->will($this->returnValue(\React\Promise\resolve(true)));

        $downloader = $this->getDownloaderMock(null, null, $process, $filesystem);
        $downloader->prepare('uninstall', $packageMock, $this->workingDir);
        $downloader->remove($packageMock, $this->workingDir);
        $downloader->cleanup('uninstall', $packageMock, $this->workingDir);
    }

    public function testGetInstallationSource()
    {
        $downloader = $this->getDownloaderMock(null);

        $this->assertEquals('source', $downloader->getInstallationSource());
    }
}
