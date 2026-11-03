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

namespace Composer\Test\Repository\Vcs;

use Composer\Config;
use Composer\Repository\Vcs\GitDriver;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Git as GitUtil;
use Composer\Util\Platform;

class GitDriverTest extends TestCase
{
    /** @var Config */
    private $config;
    /** @var string */
    private $home;
    /** @var false|string */
    private $networkEnv;
    /** @var string|false|null */
    private $gitVersion;

    public function setUp(): void
    {
        $this->home = self::getUniqueTmpDirectory();
        $this->config = new Config();
        $this->config->merge([
            'config' => [
                'home' => $this->home,
            ],
        ]);
        $this->networkEnv = Platform::getEnv('COMPOSER_DISABLE_NETWORK');
        $this->gitVersion = $this->getGitVersionProperty()->getValue();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
        // restore rather than reset, as later tests rely on the version cached by earlier ones
        $this->getGitVersionProperty()->setValue(null, $this->gitVersion);
        if ($this->networkEnv === false) {
            Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        } else {
            Platform::putEnv('COMPOSER_DISABLE_NETWORK', $this->networkEnv);
        }
    }

    public function testGetRootIdentifierFromRemoteLocalRepository(): void
    {
        $process = $this->getProcessExecutorMock();
        $io = $this->getIOMock();

        $driver = new GitDriver(['url' => $this->home], $io, $this->config, $this->getHttpDownloaderMock(), $process);
        $this->setRepoDir($driver, $this->home);

        $stdoutFailure = <<<GITFAILURE
fatal: could not read Username for 'https://example.org/acme.git': terminal prompts disabled
GITFAILURE;

        $stdout = <<<GIT
* main
  2.2
  1.10
GIT;

        $process
            ->expects([[
                'cmd' => ['git', 'branch', '--no-color'],
                'stdout' => $stdout,
            ]], true);

        self::assertSame('main', $driver->getRootIdentifier());
    }

    public function testGetRootIdentifierFromRemote(): void
    {
        $process = $this->getProcessExecutorMock();
        $io = $this->getIOMock();

        $io->expects([], true);

        $driver = new GitDriver(['url' => 'https://example.org/acme.git'], $io, $this->config, $this->getHttpDownloaderMock(), $process);
        $this->setRepoDir($driver, $this->home);

        $stdout = <<<GIT
* remote origin
  Fetch URL: https://example.org/acme.git
  Push  URL: https://example.org/acme.git
  HEAD branch: main
  Remote branches:
    1.10                       tracked
    2.2                        tracked
    main                       tracked
GIT;

        $process
            ->expects([[
                'cmd' => ['git', 'remote', '-v'],
                'stdout' => '',
            ], [
                'cmd' => ['git', 'remote', 'set-url', 'origin', '--', 'https://example.org/acme.git'],
                'stdout' => '',
            ], [
                'cmd' => ['git', 'remote', 'show', 'origin'],
                'stdout' => $stdout,
            ], [
                'cmd' => ['git', 'remote', 'set-url', 'origin', '--', 'https://example.org/acme.git'],
                'stdout' => '',
            ]]);

        self::assertSame('main', $driver->getRootIdentifier());
    }

    /**
     * @dataProvider provideMirrorHeadCases
     */
    public function testGetRootIdentifierAfterMirrorSync(string $gitVersion, bool $existingMirror, bool $expectRemoteShow): void
    {
        Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        $this->getGitVersionProperty()->setValue(null, $gitVersion);

        $url = 'https://example.org/acme.git';
        $repoDir = $this->config->get('cache-vcs-dir').'/https---example.org-acme.git/';
        if ($existingMirror) {
            mkdir($repoDir, 0777, true);
        }

        $process = $this->getProcessExecutorMock();
        $expected = [];
        if ($existingMirror) {
            $expected[] = ['cmd' => ['git', 'rev-parse', '--git-dir'], 'stdout' => ".\n"];
            $expected[] = ['cmd' => ['git', 'remote', '-v']];
            $expected[] = ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]];
            $expected[] = ['cmd' => ['git', '-c', 'remote.origin.followRemoteHEAD=always', 'remote', 'update', '--prune', 'origin']];
            $expected[] = ['cmd' => ['git', 'gc', '--auto']];
        } else {
            $expected[] = ['cmd' => ['git', 'clone', '--mirror', '--', $url, $repoDir]];
        }
        $expected[] = ['cmd' => ['git', 'remote', '-v']];
        $expected[] = ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]];
        $expected[] = ['cmd' => ['git', 'show-ref', '--tags', '--dereference']];
        $expected[] = ['cmd' => ['git', 'branch', '--no-color', '--no-abbrev', '-v']];
        if ($expectRemoteShow) {
            $expected[] = ['cmd' => ['git', 'remote', '-v']];
            $expected[] = ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]];
            $expected[] = ['cmd' => ['git', 'remote', 'show', 'origin'], 'stdout' => "* remote origin\n  HEAD branch: main\n"];
            $expected[] = ['cmd' => ['git', 'remote', '-v']];
            $expected[] = ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]];
        } else {
            $expected[] = ['cmd' => ['git', 'branch', '--no-color'], 'stdout' => "  2.2\n* main\n"];
        }
        $process->expects($expected, true);

        // errors swallowed by GitUtil would otherwise hide unexpected commands
        $io = $this->getIOMock();
        $io->expects([], true);

        $driver = new GitDriver(['url' => $url], $io, $this->config, $this->getHttpDownloaderMock(), $process);
        $driver->initialize();

        self::assertSame('main', $driver->getRootIdentifier());
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function provideMirrorHeadCases(): iterable
    {
        yield 'fresh clone on old git uses mirror HEAD' => ['2.47.0', false, false];
        yield 'updated mirror on old git asks the remote' => ['2.47.0', true, true];
        yield 'updated mirror on git 2.48+ uses mirror HEAD' => ['2.48.0', true, false];
    }

    public function testGetRootIdentifierAsksRemoteAfterFailedMirrorSync(): void
    {
        Platform::clearEnv('COMPOSER_DISABLE_NETWORK');
        $this->getGitVersionProperty()->setValue(null, '2.48.0');

        $url = 'https://example.org/acme.git';
        $repoDir = $this->config->get('cache-vcs-dir').'/https---example.org-acme.git/';
        mkdir($repoDir, 0777, true);

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['cmd' => ['git', 'rev-parse', '--git-dir'], 'stdout' => ".\n"],
            ['cmd' => ['git', 'remote', '-v']],
            ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]],
            ['cmd' => ['git', '-c', 'remote.origin.followRemoteHEAD=always', 'remote', 'update', '--prune', 'origin'], 'return' => 1],
            ['cmd' => ['git', 'remote', '-v']],
            ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]],
            ['cmd' => ['git', 'show-ref', '--tags', '--dereference']],
            ['cmd' => ['git', 'branch', '--no-color', '--no-abbrev', '-v']],
            ['cmd' => ['git', 'remote', '-v']],
            ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]],
            ['cmd' => ['git', 'remote', 'show', 'origin'], 'stdout' => "* remote origin\n  HEAD branch: main\n"],
            ['cmd' => ['git', 'remote', '-v']],
            ['cmd' => ['git', 'remote', 'set-url', 'origin', '--', $url]],
        ], true);

        $driver = new GitDriver(['url' => $url], $this->getIOMock(), $this->config, $this->getHttpDownloaderMock(), $process);
        $driver->initialize();

        self::assertSame('main', $driver->getRootIdentifier());
    }

    public function testGetRootIdentifierFromLocalWithNetworkDisabled(): void
    {
        Platform::putEnv('COMPOSER_DISABLE_NETWORK', '1');

        $process = $this->getProcessExecutorMock();
        $io = $this->getIOMock();

        $driver = new GitDriver(['url' => 'https://example.org/acme.git'], $io, $this->config, $this->getHttpDownloaderMock(), $process);
        $this->setRepoDir($driver, $this->home);

        $stdout = <<<GIT
* main
  2.2
  1.10
GIT;

        $process
            ->expects([[
                'cmd' => ['git', 'branch', '--no-color'],
                'stdout' => $stdout,
            ]]);

        self::assertSame('main', $driver->getRootIdentifier());
    }

    public function testGetBranchesFilterInvalidBranchNames(): void
    {
        $process = $this->getProcessExecutorMock();
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $driver = new GitDriver(['url' => 'https://example.org/acme.git'], $io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);
        $this->setRepoDir($driver, $this->home);

        // Branches starting with a - character are not valid git branches names
        // Still assert that they get filtered to prevent issues later on
        $stdout = <<<GIT
* main 089681446ba44d6d9004350192486f2ceb4eaa06 commit
  2.2  12681446ba44d6d9004350192486f2ceb4eaa06 commit
  -h   089681446ba44d6d9004350192486f2ceb4eaa06 commit
GIT;

        $process
            ->expects([[
                'cmd' => ['git', 'branch', '--no-color', '--no-abbrev', '-v'],
                'stdout' => $stdout,
            ]]);

        $branches = $driver->getBranches();
        self::assertSame([
            'main' => '089681446ba44d6d9004350192486f2ceb4eaa06',
            '2.2' => '12681446ba44d6d9004350192486f2ceb4eaa06',
        ], $branches);
    }

    public function testFileGetContentInvalidIdentifier(): void
    {
        self::expectException('\RuntimeException');

        $process = $this->getProcessExecutorMock();
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $driver = new GitDriver(['url' => 'https://example.org/acme.git'], $io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        self::assertNull($driver->getFileContent('file.txt', 'h'));

        $driver->getFileContent('file.txt', '-h');
    }

    public function testGetChangeDateInvalidIdentifier(): void
    {
        self::expectException('\RuntimeException');

        $process = $this->getProcessExecutorMock();
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $driver = new GitDriver(['url' => 'https://example.org/acme.git'], $io, $this->config, $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(), $process);

        $driver->getChangeDate('-n1 --format=%at HEAD');
    }

    private function getGitVersionProperty(): \ReflectionProperty
    {
        $reflectionProperty = new \ReflectionProperty(GitUtil::class, 'version');
        if (\PHP_VERSION_ID < 80100) {
            $reflectionProperty->setAccessible(true);
        }

        return $reflectionProperty;
    }

    private function setRepoDir(GitDriver $driver, string $path): void
    {
        $reflectionClass = new \ReflectionClass($driver);
        $reflectionProperty = $reflectionClass->getProperty('repoDir');
        (\PHP_VERSION_ID < 80100) and $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($driver, $path);
    }
}
