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
     */
    protected function setupConfig($config = null): Config
    {
        if (!$config) {
            $config = new Config();
        }
        if (!$config->has('home')) {
            $tmpDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'cmptest-'.bin2hex(random_bytes(5));
            $config->merge(['config' => ['home' => $tmpDir]]);
        }

        return $config;
    }

    /**
     * @param \Composer\IO\IOInterface $io
     * @param \Composer\Config $config
     * @param \Composer\Test\Mock\ProcessExecutorMock $executor
     * @param \Composer\Util\Filesystem $filesystem
     */
    protected function getDownloaderMock(?\Composer\IO\IOInterface $io = null, ?Config $config = null, ?\Composer\Test\Mock\ProcessExecutorMock $executor = null, ?Filesystem $filesystem = null): GitDownloader
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
            ->will($this->returnValue(['https://example.com/composer/composer']));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('dev-master'));

        $process = $this->getProcessExecutorMock();
        $expectedPath = Platform::isWindows() ? Platform::getCwd().'/composerPath' : 'composerPath';
        $process->expects([
            ['git', 'clone', '--no-checkout', '--', 'https://example.com/composer/composer', $expectedPath],
            ['git', 'remote', 'add', 'composer', '--', 'https://example.com/composer/composer'],
            ['git', 'fetch', 'composer'],
            ['git', 'remote', 'set-url', 'origin', '--', 'https://example.com/composer/composer'],
            ['git', 'remote', 'set-url', 'composer', '--', 'https://example.com/composer/composer'],
            ['git', 'branch', '-r'],
            ['git', 'checkout', 'master', '--'],
            ['git', 'reset', '--hard', '1234567890123456789012345678901234567890', '--'],
        ], true);

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
            ->will($this->returnValue(['https://example.com/composer/composer']));
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

        $expectedPath = Platform::isWindows() ? Platform::getCwd().'/composerPath' : 'composerPath';
        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'clone', '--mirror', '--', 'https://example.com/composer/composer', $cachePath],
                'callback' => static function () use ($cachePath): void {
                    @mkdir($cachePath, 0777, true);
                }
            ],
            ['cmd' => ['git', 'rev-parse', '--git-dir'], 'stdout' => '.'],
            ['git', 'rev-parse', '--quiet', '--verify', '1234567890123456789012345678901234567890^{commit}'],
            ['git', 'clone', '--no-checkout', $cachePath, $expectedPath, '--dissociate', '--reference', $cachePath],
            ['git', 'remote', 'set-url', 'origin', '--', 'https://example.com/composer/composer'],
            ['git', 'remote', 'add', 'composer', '--', 'https://example.com/composer/composer'],
            ['git', 'branch', '-r'],
            ['cmd' => ['git', 'checkout', 'master', '--'], 'return' => 1],
            ['git', 'checkout', '-B', 'master', 'composer/master', '--'],
            ['git', 'reset', '--hard', '1234567890123456789012345678901234567890', '--'],
        ], true);

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
            ->will($this->returnValue(['https://github.com/mirrors/composer', 'https://github.com/composer/composer']));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $expectedPath = Platform::isWindows() ? Platform::getCwd().'/composerPath' : 'composerPath';
        $process->expects([
            ['cmd' => ['git', 'clone', '--no-checkout', '--', 'https://github.com/mirrors/composer', $expectedPath], 'return' => 1, 'stderr' => 'Error1'],

            ['git', 'clone', '--no-checkout', '--', 'git@github.com:mirrors/composer', $expectedPath],
            ['git', 'remote', 'add', 'composer', '--', 'git@github.com:mirrors/composer'],
            ['git', 'fetch', 'composer'],
            ['git', 'remote', 'set-url', 'origin', '--', 'git@github.com:mirrors/composer'],
            ['git', 'remote', 'set-url', 'composer', '--', 'git@github.com:mirrors/composer'],

            ['git', 'remote', 'set-url', 'origin', '--', 'https://github.com/composer/composer'],
            ['git', 'remote', 'set-url', '--push', 'origin', '--', 'git@github.com:composer/composer.git'],
            ['git', 'branch', '-r'],
            ['git', 'checkout', 'ref', '--'],
            ['git', 'reset', '--hard', 'ref', '--'],
        ], true);

        $downloader = $this->getDownloaderMock(null, new Config(), $process);
        $downloader->download($packageMock, 'composerPath');
        $downloader->prepare('install', $packageMock, 'composerPath');
        $downloader->install($packageMock, 'composerPath');
        $downloader->cleanup('install', $packageMock, 'composerPath');
    }

    public static function pushUrlProvider(): array
    {
        return [
            // ssh proto should use git@ all along
            [['ssh'],                 'git@github.com:composer/composer',     'git@github.com:composer/composer.git'],
            // auto-proto uses git@ by default for push url, but not fetch
            [['https', 'ssh', 'git'], 'https://github.com/composer/composer', 'git@github.com:composer/composer.git'],
            // if restricted to https then push url is not overwritten to git@
            [['https'],               'https://github.com/composer/composer', 'https://github.com/composer/composer.git'],
        ];
    }

    /**
     * @dataProvider pushUrlProvider
     * @param string[] $protocols
     */
    public function testDownloadAndSetPushUrlUseCustomVariousProtocolsForGithub(array $protocols, string $url, string $pushUrl): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(['https://github.com/composer/composer']));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://github.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $expectedPath = Platform::isWindows() ? Platform::getCwd().'/composerPath' : 'composerPath';
        $process->expects([
            ['git', 'clone', '--no-checkout', '--', $url, $expectedPath],
            ['git', 'remote', 'add', 'composer', '--', $url],
            ['git', 'fetch', 'composer'],
            ['git', 'remote', 'set-url', 'origin', '--', $url],
            ['git', 'remote', 'set-url', 'composer', '--', $url],

            ['git', 'remote', 'set-url', '--push', 'origin', '--', $pushUrl],
            ['git', 'branch', '-r'],
            ['git', 'checkout', 'ref', '--'],
            ['git', 'reset', '--hard', 'ref', '--'],
        ], true);

        $config = new Config();
        $config->merge(['config' => ['github-protocols' => $protocols]]);

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
            ->will($this->returnValue(['https://example.com/composer/composer']));
        $packageMock->expects($this->any())
            ->method('getSourceUrl')
            ->will($this->returnValue('https://example.com/composer/composer'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $expectedPath = Platform::isWindows() ? Platform::getCwd().'/composerPath' : 'composerPath';
        $process->expects([
            [
                'cmd' => ['git', 'clone', '--no-checkout', '--', 'https://example.com/composer/composer', $expectedPath],
                'return' => 1,
            ],
        ]);

        self::expectException('RuntimeException');
        self::expectExceptionMessage('Failed to execute git clone --no-checkout -- https://example.com/composer/composer '.$expectedPath);
        $downloader = $this->getDownloaderMock(null, null, $process);
        $downloader->download($packageMock, 'composerPath');
        $downloader->prepare('install', $packageMock, 'composerPath');
        $downloader->install($packageMock, 'composerPath');
        $downloader->cleanup('install', $packageMock, 'composerPath');
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
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(['https://github.com/composer/composer']));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['git', 'show-ref', '--head', '-d'],
            ['git', 'status', '--porcelain', '--untracked-files=no'],
            ['cmd' => ['git', 'rev-parse', '--quiet', '--verify', 'ref^{commit}'], 'return' => 1],

            // fallback commands for the above failing
            ['git', 'remote', '-v'],
            ['git', 'remote', 'set-url', 'composer', '--', 'https://github.com/composer/composer'],
            ['git', 'fetch', 'composer'],
            ['git', 'fetch', '--tags', 'composer'],

            ['git', 'remote', '-v'],
            ['git', 'remote', 'set-url', 'composer', '--', 'https://github.com/composer/composer'],

            ['git', 'branch', '-r'],
            ['git', 'checkout', 'ref', '--'],
            ['git', 'reset', '--hard', 'ref', '--'],
            ['git', 'remote', '-v'],
        ], true);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock(null, new Config(), $process);
        $downloader->download($packageMock, $this->workingDir, $packageMock);
        $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
        $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);
    }

    public function testUpdateWithNewRepoUrl(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(['https://github.com/composer/composer']));
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
        $process->expects([
            ['git', 'show-ref', '--head', '-d'],
            ['git', 'status', '--porcelain', '--untracked-files=no'],
            ['cmd' => ['git', 'rev-parse', '--quiet', '--verify', 'ref^{commit}'], 'return' => 0],

            ['git', 'remote', '-v'],
            ['git', 'remote', 'set-url', 'composer', '--', 'https://github.com/composer/composer'],

            ['git', 'branch', '-r'],
            ['git', 'checkout', 'ref', '--'],
            ['git', 'reset', '--hard', 'ref', '--'],
            [
                'cmd' => ['git', 'remote', '-v'],
                'stdout' => 'origin https://github.com/old/url (fetch)
origin https://github.com/old/url (push)
composer https://github.com/old/url (fetch)
composer https://github.com/old/url (push)
',
            ],
            ['git', 'remote', 'set-url', 'origin', '--', 'https://github.com/composer/composer'],
            ['git', 'remote', 'set-url', '--push', 'origin', '--', 'git@github.com:composer/composer.git'],
        ], true);

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
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(['https://github.com/composer/composer']));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['git', 'show-ref', '--head', '-d'],
            ['git', 'status', '--porcelain', '--untracked-files=no'],

            // commit not yet in so we try to fetch
            ['cmd' => ['git', 'rev-parse', '--quiet', '--verify', 'ref^{commit}'], 'return' => 1],

            // fail first fetch
            ['git', 'remote', '-v'],
            ['git', 'remote', 'set-url', 'composer', '--', 'https://github.com/composer/composer'],
            ['cmd' => ['git', 'fetch', 'composer'], 'return' => 1],

            // fail second fetch
            ['git', 'remote', 'set-url', 'composer', '--', 'git@github.com:composer/composer'],
            ['cmd' => ['git', 'fetch', 'composer'], 'return' => 1],

            ['git', '--version'],
        ], true);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');

        self::expectException('RuntimeException');
        self::expectExceptionMessage('Failed to clone https://github.com/composer/composer via https, ssh protocols, aborting.');
        self::expectExceptionMessageMatches('{git@github\.com:composer/composer}');
        $downloader = $this->getDownloaderMock(null, new Config(), $process);
        $downloader->download($packageMock, $this->workingDir, $packageMock);
        $downloader->prepare('update', $packageMock, $this->workingDir, $packageMock);
        $downloader->update($packageMock, $packageMock, $this->workingDir);
        $downloader->cleanup('update', $packageMock, $this->workingDir, $packageMock);
    }

    public function testUpdateDoesntThrowsRuntimeExceptionIfGitCommandFailsAtFirstButIsAbleToRecover(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $packageMock->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $packageMock->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0.0'));
        $packageMock->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue([Platform::isWindows() ? 'C:\\' : '/', 'https://github.com/composer/composer']));
        $packageMock->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('1.0.0'));

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['git', 'show-ref', '--head', '-d'],
            ['git', 'status', '--porcelain', '--untracked-files=no'],

            // commit not yet in so we try to fetch
            ['cmd' => ['git', 'rev-parse', '--quiet', '--verify', 'ref^{commit}'], 'return' => 1],

            // fail first source URL
            ['git', 'remote', '-v'],
            ['git', 'remote', 'set-url', 'composer', '--', Platform::isWindows() ? 'C:\\' : '/'],
            ['cmd' => ['git', 'fetch', 'composer'], 'return' => 1],
            ['git', '--version'],

            // commit not yet in so we try to fetch
            ['cmd' => ['git', 'rev-parse', '--quiet', '--verify', 'ref^{commit}'], 'return' => 1],

            // pass second source URL
            ['git', 'remote', '-v'],
            ['git', 'remote', 'set-url', 'composer', '--', 'https://github.com/composer/composer'],
            ['cmd' => ['git', 'fetch', 'composer'], 'return' => 0],
            ['git', 'fetch', '--tags', 'composer'],
            ['git', 'remote', '-v'],
            ['git', 'remote', 'set-url', 'composer', '--', 'https://github.com/composer/composer'],

            ['git', 'branch', '-r'],
            ['git', 'checkout', 'ref', '--'],
            ['git', 'reset', '--hard', 'ref', '--'],
            ['git', 'remote', '-v'],
        ], true);

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
            ->will($this->returnValue(['/foo/bar', 'https://github.com/composer/composer']));

        $newPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $newPackage->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $newPackage->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(['https://github.com/composer/composer']));
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

        $ioMock = $this->getIOMock();
        $ioMock->expects([
            ['text' => '{Downgrading .*}', 'regex' => true],
        ]);

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
            ->will($this->returnValue(['/foo/bar', 'https://github.com/composer/composer']));

        $newPackage = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $newPackage->expects($this->any())
            ->method('getSourceReference')
            ->will($this->returnValue('ref'));
        $newPackage->expects($this->any())
            ->method('getSourceUrls')
            ->will($this->returnValue(['https://github.com/composer/composer']));
        $newPackage->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue('dev-ref2'));
        $newPackage->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue('dev-ref2'));

        $process = $this->getProcessExecutorMock();

        $ioMock = $this->getIOMock();
        $ioMock->expects([
            ['text' => '{Upgrading .*}', 'regex' => true],
        ]);

        $this->fs->ensureDirectoryExists($this->workingDir.'/.git');
        $downloader = $this->getDownloaderMock($ioMock, null, $process);
        $downloader->download($newPackage, $this->workingDir, $oldPackage);
        $downloader->prepare('update', $newPackage, $this->workingDir, $oldPackage);
        $downloader->update($oldPackage, $newPackage, $this->workingDir);
        $downloader->cleanup('update', $newPackage, $this->workingDir, $oldPackage);
    }

    public function testRemove(): void
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['git', 'show-ref', '--head', '-d'],
            ['git', 'status', '--porcelain', '--untracked-files=no'],
        ], true);

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

        self::assertEquals('source', $downloader->getInstallationSource());
    }
}
