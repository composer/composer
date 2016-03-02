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
use Composer\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class GitDownloaderTest extends TestCase
{
    /** @var Filesystem */
    private $fs;
    /** @var string */
    private $workingDir;

    protected function setUp()
    {
        $this->fs = new Filesystem;
        $this->workingDir = $this->getUniqueTmpDirectory();
    }

    protected function tearDown()
    {
        if (is_dir($this->workingDir)) {
            $this->fs->removeDirectory($this->workingDir);
        }
    }

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
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://example.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('dev-master'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->winCompat("git clone --no-checkout 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'https://example.com/composer/composer' && git fetch composer");
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
            ->with($this->equalTo($this->winCompat("git checkout 'master' --")), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git reset --hard '1234567890123456789012345678901234567890' --")), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
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
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/mirrors/composer', 'https://github.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->winCompat("git clone --no-checkout 'https://github.com/mirrors/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'https://github.com/mirrors/composer' && git fetch composer");
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(1));

        $processExecutor->expects($this->at(1))
            ->method('getErrorOutput')
            ->with()
            ->will($this->returnValue('Error1'));

        $expectedGitCommand = $this->winCompat("git clone --no-checkout 'git@github.com:mirrors/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'git@github.com:mirrors/composer' && git fetch composer");
        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand))
            ->will($this->returnValue(0));

        $expectedGitCommand = $this->winCompat("git remote set-url origin 'https://github.com/composer/composer'");
        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $expectedGitCommand = $this->winCompat("git remote set-url --push origin 'git@github.com:composer/composer.git'");
        $processExecutor->expects($this->at(4))
            ->method('execute')
            ->with($this->equalTo($expectedGitCommand), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(5))
            ->method('execute')
            ->with($this->equalTo('git branch -r'))
            ->will($this->returnValue(0));

        $processExecutor->expects($this->at(6))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --")), $this->equalTo(null), $this->equalTo($this->winCompat('composerPath')))
            ->will($this->returnValue(0));

        $downloader = $this->getDownloaderMock(null, new Config(), $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    public function pushUrlProvider()
    {
        return array(
            // ssh proto should use git@ all along
            array(array('ssh'),                 'git@github.com:composer/composer',     'git@github.com:composer/composer.git'),
            // auto-proto uses git@ by default for push url, but not fetch
            array(array('https', 'ssh', 'git'), 'https://github.com/composer/composer', 'git@github.com:composer/composer.git'),
            // if restricted to https then push url is not overwritten to git@
            array(array('https'),               'https://github.com/composer/composer', 'https://github.com/composer/composer.git'),
        );
    }

    /**
     * @dataProvider pushUrlProvider
     */
    public function testDownloadAndSetPushUrlUseCustomVariousProtocolsForGithub($protocols, $url, $pushUrl)
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');

        $expectedGitCommand = $this->winCompat("git clone --no-checkout '{$url}' 'composerPath' && cd 'composerPath' && git remote add composer '{$url}' && git fetch composer");
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
        $config->merge(array('config' => array('github-protocols' => $protocols)));

        $downloader = $this->getDownloaderMock(null, $config, $processExecutor);
        $downloader->download($packageMock, 'composerPath');
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testDownloadThrowsRuntimeExceptionIfGitCommandFails()
    {
        $expectedGitCommand = $this->winCompat("git clone --no-checkout 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer 'https://example.com/composer/composer' && git fetch composer");
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://example.com/composer/composer')));
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
        $expectedGitUpdateCommand = $this->winCompat("git remote set-url composer 'https://github.com/composer/composer' && git fetch composer && git fetch --tags composer");

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git show-ref --head -d")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git status --porcelain --untracked-files=no")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git remote -v")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($this->winCompat($expectedGitUpdateCommand)), $this->equalTo(null), $this->equalTo($this->winCompat($this->workingDir)))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(4))
            ->method('execute')
            ->with($this->equalTo('git branch -r'))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(5))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --")), $this->equalTo(null), $this->equalTo($this->winCompat($this->workingDir)))
            ->will($this->returnValue(0));

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock(null, new Config(), $processExecutor);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
    }

    /**
     * @group failing
     * @expectedException \RuntimeException
     */
    public function testUpdateThrowsRuntimeExceptionIfGitCommandFails()
    {
        $expectedGitUpdateCommand = $this->winCompat("git remote set-url composer 'https://github.com/composer/composer' && git fetch composer && git fetch --tags composer");

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/composer/composer')));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git show-ref --head -d")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git status --porcelain --untracked-files=no")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(2))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git remote -v")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($expectedGitUpdateCommand))
            ->will($this->returnValue(1));

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock(null, new Config(), $processExecutor);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
    }

    public function testUpdateDoesntThrowsRuntimeExceptionIfGitCommandFailsAtFirstButIsAbleToRecover()
    {
        $expectedFirstGitUpdateCommand = $this->winCompat("git remote set-url composer '' && git fetch composer && git fetch --tags composer");
        $expectedSecondGitUpdateCommand = $this->winCompat("git remote set-url composer 'https://github.com/composer/composer' && git fetch composer && git fetch --tags composer");

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('/foo/bar', 'https://github.com/composer/composer')));
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git show-ref --head -d")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(1))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git status --porcelain --untracked-files=no")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(2))
           ->method('execute')
            ->with($this->equalTo($this->winCompat("git remote -v")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(3))
            ->method('execute')
            ->with($this->equalTo($expectedFirstGitUpdateCommand))
            ->will($this->returnValue(1));
        $processExecutor->expects($this->at(5))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git --version")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(6))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git remote -v")))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(7))
            ->method('execute')
            ->with($this->equalTo($expectedSecondGitUpdateCommand))
            ->will($this->returnValue(0));
        $processExecutor->expects($this->at(9))
            ->method('execute')
            ->with($this->equalTo($this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --")), $this->equalTo(null), $this->equalTo($this->winCompat($this->workingDir)))
            ->will($this->returnValue(0));

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock(null, new Config(), $processExecutor);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
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
        if (Platform::isWindows()) {
            $cmd = str_replace('cd ', 'cd /D ', $cmd);
            $cmd = str_replace('composerPath', getcwd().'/composerPath', $cmd);

            return str_replace('""', '', strtr($cmd, "'", '"'));
        }

        return $cmd;
    }
}
