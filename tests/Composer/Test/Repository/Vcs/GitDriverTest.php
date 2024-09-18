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
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\GitDriver;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class GitDriverTest extends TestCase
{
    /** @var Config */
    private $config;
    /** @var string */
    private $home;
    /** @var false|string */
    private $networkEnv;

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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
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
                'cmd' => 'git branch --no-color',
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
                'cmd' => 'git remote -v',
                'stdout' => '',
            ], [
                'cmd' => Platform::isWindows() ? "git remote set-url origin -- https://example.org/acme.git && git remote show origin && git remote set-url origin -- https://example.org/acme.git" : "git remote set-url origin -- 'https://example.org/acme.git' && git remote show origin && git remote set-url origin -- 'https://example.org/acme.git'",
                'stdout' => $stdout,
            ]]);

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
                'cmd' => 'git branch --no-color',
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
                'cmd' => 'git branch --no-color --no-abbrev -v',
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

    private function setRepoDir(GitDriver $driver, string $path): void
    {
        $reflectionClass = new \ReflectionClass($driver);
        $reflectionProperty = $reflectionClass->getProperty('repoDir');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($driver, $path);
    }
}
