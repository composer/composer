<?php declare(strict_types=1);

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
use Composer\Pcre\Preg;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class GitDownloaderTest extends TestCase
{
    /** @var Filesystem */
    private $fs;
    /** @var string */
    private $workingDir;

    protected function setUp(): void
    {
        $this->skipIfNotExecutable('git');

        $this->initGitVersion('1.0.0');

        $this->fs = new Filesystem;
        $this->workingDir = self::getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->workingDir)) {
            $this->fs->removeDirectory($this->workingDir);
        }

        $this->initGitVersion(false);
    }

    /**
     * @param string|bool $version
     * @return void
     */
    private function initGitVersion($version): void
    {
        // reset the static version cache
        $refl = new \ReflectionProperty('Composer\Util\Git', 'version');
        $refl->setAccessible(true);
        $refl->setValue(null, $version);
    }

    /**
     * @param ?\Composer\Config $config
     * @return \Composer\Config
     */
    protected function setupConfig($config = null): Config
    {
        if (!$config) {
            $config = new Config();
        }
        if (!$config->has('home')) {
            $tmpDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cmptest-'.md5(uniqid('', true));
            $config->merge(array('config' => array('home' => $tmpDir)));
        }

        return $config;
    }

    /**
     * @param \Composer\IO\IOInterface $io
     * @param \Composer\Config $config
     * @param \Composer\Test\Mock\ProcessExecutorMock $executor
     * @param \Composer\Util\Filesystem $filesystem
     * @return GitDownloader
     */
    protected function getDownloaderMock(\Composer\IO\IOInterface $io = null, Config $config = null, \Composer\Test\Mock\ProcessExecutorMock $executor = null, Filesystem $filesystem = null): GitDownloader
    {
        $io = $io ?: $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $executor = $executor ?: $this->getProcessExecutorMock();
        $filesystem = $filesystem ?: $this->getMockBuilder('Composer\Util\Filesystem')->getMock();
        $config = $this->setupConfig($config);

        return new GitDownloader($io, $config, $executor, $filesystem);
    }

    public function testDownloadForPackageWithoutSourceReference(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        self::expectException('InvalidArgumentException');

        $downloader = $this->getDownloaderMock();
        $downloader->download($packageMock, '/path');
        $downloader->prepare('install', $packageMock, '/path');
        $downloader->install($packageMock, '/path');
        $downloader->cleanup('install', $packageMock, '/path');
    }

    public function testDownload(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
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

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->winCompat("git clone --no-checkout -- 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer -- 'https://example.com/composer/composer' && git fetch composer && git remote set-url origin -- 'https://example.com/composer/composer' && git remote set-url composer -- 'https://example.com/composer/composer'"),
            $this->winCompat("git branch -r"),
            $this->winCompat("(git checkout 'master' -- || git checkout -B 'master' 'composer/master' --) && git reset --hard '1234567890123456789012345678901234567890' --"),
        ), true);

        $downloader = $this->getDownloaderMock(null, null, $process);
        $downloader->download($packageMock, 'composerPath');
        $downloader->prepare('install', $packageMock, 'composerPath');
        $downloader->install($packageMock, 'composerPath');
        $downloader->cleanup('install', $packageMock, 'composerPath');
    }

    public function testDownloadWithCache(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
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

        $this->initGitVersion('2.17.0');

        $config = new Config;
        $this->setupConfig($config);
        $cachePath = $config->get('cache-vcs-dir').'/'.Preg::replace('{[^a-z0-9.]}i', '-', 'https://example.com/composer/composer').'/';

        $filesystem = new \Composer\Util\Filesystem;
        $filesystem->removeDirectory($cachePath);

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            array('cmd' => $this->winCompat(sprintf("git clone --mirror -- 'https://example.com/composer/composer' '%s'", $cachePath)), 'callback' => function () use ($cachePath): void {
                @mkdir($cachePath, 0777, true);
            }),
            array('cmd' => 'git rev-parse --git-dir', 'stdout' => '.'),
            $this->winCompat('git rev-parse --quiet --verify \'1234567890123456789012345678901234567890^{commit}\''),
            $this->winCompat(sprintf("git clone --no-checkout '%1\$s' 'composerPath' --dissociate --reference '%1\$s' && cd 'composerPath' && git remote set-url origin -- 'https://example.com/composer/composer' && git remote add composer -- 'https://example.com/composer/composer'", $cachePath)),
            'git branch -r',
            $this->winCompat("(git checkout 'master' -- || git checkout -B 'master' 'composer/master' --) && git reset --hard '1234567890123456789012345678901234567890' --"),
        ), true);

        $downloader = $this->getDownloaderMock(null, $config, $process);
        $downloader->download($packageMock, 'composerPath');
        $downloader->prepare('install', $packageMock, 'composerPath');
        $downloader->install($packageMock, 'composerPath');
        $downloader->cleanup('install', $packageMock, 'composerPath');
        @rmdir($cachePath);
    }

    public function testDownloadUsesVariousProtocolsAndSetsPushUrlForGithub(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
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

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            array(
                'cmd' => $this->winCompat("git clone --no-checkout -- 'https://github.com/mirrors/composer' 'composerPath' && cd 'composerPath' && git remote add composer -- 'https://github.com/mirrors/composer' && git fetch composer && git remote set-url origin -- 'https://github.com/mirrors/composer' && git remote set-url composer -- 'https://github.com/mirrors/composer'"),
                'return' => 1,
                'stderr' => 'Error1',
            ),
            $this->winCompat("git clone --no-checkout -- 'git@github.com:mirrors/composer' 'composerPath' && cd 'composerPath' && git remote add composer -- 'git@github.com:mirrors/composer' && git fetch composer && git remote set-url origin -- 'git@github.com:mirrors/composer' && git remote set-url composer -- 'git@github.com:mirrors/composer'"),
            $this->winCompat("git remote set-url origin -- 'https://github.com/composer/composer'"),
            $this->winCompat("git remote set-url --push origin -- 'git@github.com:composer/composer.git'"),
            'git branch -r',
            $this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --"),
        ), true);

        $downloader = $this->getDownloaderMock(null, new Config(), $process);
        $downloader->download($packageMock, 'composerPath');
        $downloader->prepare('install', $packageMock, 'composerPath');
        $downloader->install($packageMock, 'composerPath');
        $downloader->cleanup('install', $packageMock, 'composerPath');
    }

    public function pushUrlProvider(): array
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
     * @param string[] $protocols
     * @param string $url
     * @param string $pushUrl
     */
    public function testDownloadAndSetPushUrlUseCustomVariousProtocolsForGithub(array $protocols, string $url, string $pushUrl): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
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

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->winCompat("git clone --no-checkout -- '{$url}' 'composerPath' && cd 'composerPath' && git remote add composer -- '{$url}' && git fetch composer && git remote set-url origin -- '{$url}' && git remote set-url composer -- '{$url}'"),
            $this->winCompat("git remote set-url --push origin -- '{$pushUrl}'"),
            'git branch -r',
            $this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --"),
        ), true);

        $config = new Config();
        $config->merge(array('config' => array('github-protocols' => $protocols)));

        $downloader = $this->getDownloaderMock(null, $config, $process);
        $downloader->download($packageMock, 'composerPath');
        $downloader->prepare('install', $packageMock, 'composerPath');
        $downloader->install($packageMock, 'composerPath');
        $downloader->cleanup('install', $packageMock, 'composerPath');
    }

    public function testDownloadThrowsRuntimeExceptionIfGitCommandFails(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://example.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            array(
                'cmd' => $this->winCompat("git clone --no-checkout -- 'https://example.com/composer/composer' 'composerPath' && cd 'composerPath' && git remote add composer -- 'https://example.com/composer/composer' && git fetch composer && git remote set-url origin -- 'https://example.com/composer/composer' && git remote set-url composer -- 'https://example.com/composer/composer'"),
                'return' => 1,
            ),
        ));

        // not using PHPUnit's expected exception because Prophecy exceptions extend from RuntimeException too so it is not safe
        try {
            $downloader = $this->getDownloaderMock(null, null, $process);
            $downloader->download($packageMock, 'composerPath');
            $downloader->prepare('install', $packageMock, 'composerPath');
            $downloader->install($packageMock, 'composerPath');
            $downloader->cleanup('install', $packageMock, 'composerPath');

            $this->fail('This test should throw');
        } catch (\RuntimeException $e) {
            if ('RuntimeException' !== get_class($e)) {
                throw $e;
            }
            $this->assertEquals('RuntimeException', get_class($e));
        }
    }

    public function testUpdateforPackageWithoutSourceReference(): void
    {
        $initialPackageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $sourcePackageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $sourcePackageMock->expects($this->once())
            ->method('getSourceReference')
            ->will($this->returnValue(null));

        self::expectException('InvalidArgumentException');

        $downloader = $this->getDownloaderMock();
        $downloader->download($sourcePackageMock, '/path', $initialPackageMock);
        $downloader->prepare('update', $sourcePackageMock, '/path', $initialPackageMock);
        $downloader->update($initialPackageMock, $sourcePackageMock, '/path');
        $downloader->cleanup('update', $sourcePackageMock, '/path', $initialPackageMock);
    }

    public function testUpdate(): void
    {
        $expectedGitUpdateCommand = $this->winCompat("(git remote set-url composer -- 'https://github.com/composer/composer' && git rev-parse --quiet --verify 'ref^{commit}' || (git fetch composer && git fetch --tags composer)) && git remote set-url composer -- 'https://github.com/composer/composer'");

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->winCompat('git show-ref --head -d'),
            $this->winCompat('git status --porcelain --untracked-files=no'),
            $this->winCompat('git remote -v'),
            $expectedGitUpdateCommand,
            $this->winCompat('git branch -r'),
            $this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --"),
            $this->winCompat('git remote -v'),
        ), true);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock(null, new Config(), $process);
        $downloader->download($packageMock, $this->workingDir, $packageMock);
        $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
        $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);
    }

    public function testUpdateWithNewRepoUrl(): void
    {
        $expectedGitUpdateCommand = $this->winCompat("(git remote set-url composer -- 'https://github.com/composer/composer' && git rev-parse --quiet --verify 'ref^{commit}' || (git fetch composer && git fetch --tags composer)) && git remote set-url composer -- 'https://github.com/composer/composer'");

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
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
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->winCompat("git show-ref --head -d"),
            $this->winCompat("git status --porcelain --untracked-files=no"),
            $this->winCompat("git remote -v"),
            $this->winCompat($expectedGitUpdateCommand),
            'git branch -r',
            $this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --"),
            array(
                'cmd' => $this->winCompat("git remote -v"),
                'stdout' => 'origin https://github.com/old/url (fetch)
origin https://github.com/old/url (push)
composer https://github.com/old/url (fetch)
composer https://github.com/old/url (push)
',
            ),
            $this->winCompat("git remote set-url origin -- 'https://github.com/composer/composer'"),
            $this->winCompat("git remote set-url --push origin -- 'git@github.com:composer/composer.git'"),
        ), true);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock(null, new Config(), $process);
        $downloader->download($packageMock, $this->workingDir, $packageMock);
        $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
        $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);
    }

    /**
     * @group failing
     */
    public function testUpdateThrowsRuntimeExceptionIfGitCommandFails(): void
    {
        $expectedGitUpdateCommand = $this->winCompat("(git remote set-url composer -- 'https://github.com/composer/composer' && git rev-parse --quiet --verify 'ref^{commit}' || (git fetch composer && git fetch --tags composer)) && git remote set-url composer -- 'https://github.com/composer/composer'");
        $expectedGitUpdateCommand2 = $this->winCompat("(git remote set-url composer -- 'git@github.com:composer/composer' && git rev-parse --quiet --verify 'ref^{commit}' || (git fetch composer && git fetch --tags composer)) && git remote set-url composer -- 'git@github.com:composer/composer'");

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->winCompat('git show-ref --head -d'),
            $this->winCompat('git status --porcelain --untracked-files=no'),
            $this->winCompat('git remote -v'),
            array(
                'cmd' => $expectedGitUpdateCommand,
                'return' => 1,
            ),
            array(
                'cmd' => $expectedGitUpdateCommand2,
                'return' => 1,
            ),
            $this->winCompat('git --version'),
        ), true);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');

        // not using PHPUnit's expected exception because Prophecy exceptions extend from RuntimeException too so it is not safe
        try {
            $downloader = $this->getDownloaderMock(null, new Config(), $process);
            $downloader->download($packageMock, $this->workingDir, $packageMock);
            $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
            $downloader->update($packageMock, $packageMock, $this->workingDir);
            $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);

            $this->fail('This test should throw');
        } catch (\RuntimeException $e) {
            if ('RuntimeException' !== get_class($e)) {
                throw $e;
            }
            $this->assertEquals('RuntimeException', get_class($e));
        }
    }

    public function testUpdateDoesntThrowsRuntimeExceptionIfGitCommandFailsAtFirstButIsAbleToRecover(): void
    {
        $expectedFirstGitUpdateCommand = $this->winCompat("(git remote set-url composer -- '".(Platform::isWindows() ? 'C:\\' : '/')."' && git rev-parse --quiet --verify 'ref^{commit}' || (git fetch composer && git fetch --tags composer)) && git remote set-url composer -- '".(Platform::isWindows() ? 'C:\\' : '/')."'");
        $expectedSecondGitUpdateCommand = $this->winCompat("(git remote set-url composer -- 'https://github.com/composer/composer' && git rev-parse --quiet --verify 'ref^{commit}' || (git fetch composer && git fetch --tags composer)) && git remote set-url composer -- 'https://github.com/composer/composer'");

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array(Platform::isWindows() ? 'C:\\' : '/', 'https://github.com/composer/composer')));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $this->winCompat('git show-ref --head -d'),
            $this->winCompat('git status --porcelain --untracked-files=no'),
            $this->winCompat('git remote -v'),
            array(
                'cmd' => $expectedFirstGitUpdateCommand,
                'return' => 1,
            ),
            $this->winCompat('git --version'),
            $this->winCompat('git remote -v'),
            array(
                'cmd' => $expectedSecondGitUpdateCommand,
                'return' => 0,
            ),
            $this->winCompat('git branch -r'),
            $this->winCompat("git checkout 'ref' -- && git reset --hard 'ref' --"),
            $this->winCompat('git remote -v'),
        ), true);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock(null, new Config(), $process);
        $downloader->download($packageMock, $this->workingDir, $packageMock);
        $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
        $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);
    }

    public function testDowngradeShowsAppropriateMessage(): void
    {
        $oldPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $oldPackage->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.2.0.0'));
        $oldPackage->expects($this->any())
            ->method('getFullPrettyVersion')
            ->will($this->returnValue('1.2.0'));
        $oldPackage->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $oldPackage->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('/foo/bar', 'https://github.com/composer/composer')));

        $newPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $newPackage->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $newPackage->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/composer/composer')));
        $newPackage->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $newPackage->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));
        $newPackage->expects($this->any())
            ->method('getFullPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();

        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $ioMock->expects($this->atLeastOnce())
            ->method('writeError')
            ->withConsecutive(
                [$this->stringContains('Downgrading')]
            );

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock($ioMock, null, $process);
        $downloader->download($newPackage, $this->workingDir, $oldPackage);
        $downloader->prepare('update', $newPackage, $this->workingDir, $oldPackage);
        $downloader->update($oldPackage, $newPackage, $this->workingDir);
        $downloader->cleanup('update', $newPackage, $this->workingDir, $oldPackage);
    }

    public function testNotUsingDowngradingWithReferences(): void
    {
        $oldPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $oldPackage->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('dev-ref'));
        $oldPackage->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $oldPackage->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('/foo/bar', 'https://github.com/composer/composer')));

        $newPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $newPackage->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $newPackage->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(array('https://github.com/composer/composer')));
        $newPackage->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('dev-ref2'));
        $newPackage->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('dev-ref2'));

        $process = $this->getProcessExecutorMock();

        $ioMock = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $ioMock->expects($this->atLeastOnce())
            ->method('writeError')
            ->withConsecutive(
                [$this->stringContains('Upgrading')]
            );

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock($ioMock, null, $process);
        $downloader->download($newPackage, $this->workingDir, $oldPackage);
        $downloader->prepare('update', $newPackage, $this->workingDir, $oldPackage);
        $downloader->update($oldPackage, $newPackage, $this->workingDir);
        $downloader->cleanup('update', $newPackage, $this->workingDir, $oldPackage);
    }

    public function testRemove(): void
    {
        $expectedGitResetCommand = $this->winCompat("git status --porcelain --untracked-files=no");

        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            'git show-ref --head -d',
            $expectedGitResetCommand,
        ), true);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');

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

    public function testGetInstallationSource(): void
    {
        $downloader = $this->getDownloaderMock();

        $this->assertEquals('source', $downloader->getInstallationSource());
    }

    /**
     * @param string $cmd
     * @return string
     */
    private function winCompat(string $cmd): string
    {
        if (Platform::isWindows()) {
            $cmd = str_replace('cd ', 'cd /D ', $cmd);
            $cmd = str_replace('composerPath', Platform::getCwd().'/composerPath', $cmd);

            return $this->getCmd($cmd);
        }

        return $cmd;
    }
}
