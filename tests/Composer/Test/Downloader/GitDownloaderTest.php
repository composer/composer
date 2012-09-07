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
use Composer\Config;
use Composer\Util\Filesystem;

class GitDownloaderTest extends \PHPUnit_Framework_TestCase
{
    protected function getDownloaderMock($io = null, $config = null, $executor = null, $filesystem = null)
    {
        $io = $io ?: $this->getMock('Composer\IO\IOInterface');
        $executor = $executor ?: $this->getMock('Composer\Util\ProcessExecutor');
        $filesystem = $filesystem ?: $this->getMock('Composer\Util\Filesystem');
        if (!$config) {
            $config = new Config();
        }

        return new GitDownloader($io, $config, $executor, $filesystem);
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
            ->will($this->returnValue('1234567890123456789012345678901234567890'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('dev-master'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->winCompat("git clone 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'https://example.com/composer/composer' && git fetch composer");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git branch -r")), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git checkout 'master'")), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git reset --hard '1234567890123456789012345678901234567890'")), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, null, $processExecutor);
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
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->winCompat("git clone 'git://github.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'git://github.com/composer/composer' && git fetch composer");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(1));

        $expectedGitCommand = $this->winCompat("git clone 'https://github.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'https://github.com/composer/composer' && git fetch composer");
        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $expectedGitCommand = $this->winCompat("git remote set-url --push origin 'git@github.com:composer/composer.git'");
        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(4))
            ->method('execute')
            ->with($this->equalTo('git branch -r'))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(5))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git checkout 'ref' && git reset --hard 'ref'")), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, new Config(), $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    public function pushUrlProvider()
    {
        return array(
            array('git', 'git@github.com:composer/composer.git'),
            array('https', 'https://github.com/composer/composer.git'),
        );
    }

    /**
     * @dataProvider pushUrlProvider
     */
    public function testDownloadAndSetPushUrlUseCustomVariousProtocolsForGithub($protocol, $pushUrl)
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->winCompat("git clone '{$protocol}://github.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer '{$protocol}://github.com/composer/composer' && git fetch composer");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $expectedGitCommand = $this->winCompat("git remote set-url --push origin '{$pushUrl}'");
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->exactly(4))
            ->method('execute')
            ->will($this->returnValue(0));

        $config = new Config();
        $config->merge(array('config' => array('github-protocols' => array($protocol))));

        $downloader = $this->getDownloaderMock(null, $config, $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testDownloadThrowsRuntimeExceptionIfGitCommandFails()
    {
        $expectedGitCommand = $this->winCompat("git clone 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'https://example.com/composer/composer' && git fetch composer");
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(1));

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
        $expectedGitUpdateCommand = $this->winCompat("git remote set-url composer 'git://github.com/composer/composer' && git fetch composer && git fetch --tags composer");

        $tmpDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cmptest-'.md5(uniqid('', true));
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($tmpDir.'/.git');
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git status --porcelain --untracked-files=no")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git remote -v")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($expectedGitUpdateCommand))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo('git branch -r'))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(4))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git checkout 'ref' && git reset --hard 'ref'")), $this->equalTo(null), $this->equalTo($this->winCompat($tmpDir)))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, new Config(), $processExecutor);
        $downloader->update($packageMock, $packageMock, $tmpDir);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testUpdateThrowsRuntimeExceptionIfGitCommandFails()
    {
        $expectedGitUpdateCommand = $this->winCompat("git remote set-url composer 'git://github.com/composer/composer' && git fetch composer && git fetch --tags composer");

        $tmpDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cmptest-'.md5(uniqid('', true));
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($tmpDir.'/.git');
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
            ->with($this->equalTo($this->winCompat("git status --porcelain --untracked-files=no")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git remote -v")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($expectedGitUpdateCommand))
            ->will($this->returnValue(1));

        $downloader = $this->getDownloaderMock(null, new Config(), $processExecutor);
        $downloader->update($packageMock, $packageMock, $tmpDir);
    }

    public function testRemove()
    {
        $expectedGitResetCommand = $this->winCompat("cd 'composerPath' && git status --porcelain --untracked-files=no");

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->any())
            ->method('execute')
            ->with($this->equalTo($expectedGitResetCommand))
            ->will($this->returnValue(0));
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
        $downloader = $this->getDownloaderMock();

        $this->assertEquals('source', $downloader->getInstallationSource());
    }

    private function winCompat($cmd)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $cmd = str_replace('cd ', 'cd /D ', $cmd);
            $cmd = str_replace('composerPath', getcwd().'/composerPath', $cmd);

            return strtr($cmd, "'", '"');
        }

        return $cmd;
    }
}
