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

namespace Composer\Test\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\Git;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Test\TestCase;

class GitTest extends TestCase
{
    /** @var Git */
    private $git;
    /** @var IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;
    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var ProcessExecutorMock */
    private $process;
    /** @var Filesystem&\PHPUnit\Framework\MockObject\MockObject */
    private $fs;

    protected function setUp(): void
    {
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->config = $this->getMockBuilder('Composer\Config')->disableOriginalConstructor()->getMock();
        $this->process = $this->getProcessExecutorMock();
        $this->fs = $this->getMockBuilder('Composer\Util\Filesystem')->disableOriginalConstructor()->getMock();
        $this->git = new Git($this->io, $this->config, $this->process, $this->fs);
    }

    /**
     * @dataProvider publicGithubNoCredentialsProvider
     */
    public function testRunCommandPublicGitHubRepositoryNotInitialClone(string $protocol, string $expectedUrl): void
    {
        $commandCallable = function ($url) use ($expectedUrl): string {
            self::assertSame($expectedUrl, $url);

            return 'git command';
        };

        $this->mockConfig($protocol);

        $this->process->expects(['git command'], true);

        $this->git->runCommand($commandCallable, 'https://github.com/acme/repo', null, true);
    }

    public static function publicGithubNoCredentialsProvider(): array
    {
        return [
            ['ssh', 'git@github.com:acme/repo'],
            ['https', 'https://github.com/acme/repo'],
        ];
    }

    public function testRunCommandPrivateGitHubRepositoryNotInitialCloneNotInteractiveWithoutAuthentication(): void
    {
        self::expectException('RuntimeException');

        $commandCallable = function ($url): string {
            self::assertSame('https://github.com/acme/repo', $url);

            return 'git command';
        };

        $this->mockConfig('https');

        $this->process->expects([
            ['cmd' => 'git command', 'return' => 1],
            ['cmd' => 'git --version', 'return' => 0],
        ], true);

        $this->git->runCommand($commandCallable, 'https://github.com/acme/repo', null, true);
    }

    /**
     * @dataProvider privateGithubWithCredentialsProvider
     */
    public function testRunCommandPrivateGitHubRepositoryNotInitialCloneNotInteractiveWithAuthentication(string $gitUrl, string $protocol, string $gitHubToken, string $expectedUrl, int $expectedFailuresBeforeSuccess): void
    {
        $commandCallable = static function ($url) use ($expectedUrl): string {
            if ($url !== $expectedUrl) {
                return 'git command failing';
            }

            return 'git command ok';
        };

        $this->mockConfig($protocol);

        $expectedCalls = array_fill(0, $expectedFailuresBeforeSuccess, ['cmd' => 'git command failing', 'return' => 1]);
        $expectedCalls[] = ['cmd' => 'git command ok', 'return' => 0];

        $this->process->expects($expectedCalls, true);

        $this->io
            ->method('isInteractive')
            ->willReturn(false);

        $this->io
            ->expects($this->atLeastOnce())
            ->method('hasAuthentication')
            ->with($this->equalTo('github.com'))
            ->willReturn(true);

        $this->io
            ->expects($this->atLeastOnce())
            ->method('getAuthentication')
            ->with($this->equalTo('github.com'))
            ->willReturn(['username' => 'token', 'password' => $gitHubToken]);

        $this->git->runCommand($commandCallable, $gitUrl, null, true);
    }

    /**
     * @dataProvider privateBitbucketWithCredentialsProvider
     */
    public function testRunCommandPrivateBitbucketRepositoryNotInitialCloneNotInteractiveWithAuthentication(string $gitUrl, ?string $bitbucketToken, string $expectedUrl, int $expectedFailuresBeforeSuccess, int $bitbucket_git_auth_calls = 0): void
    {
        $commandCallable = static function ($url) use ($expectedUrl): string {
            if ($url !== $expectedUrl) {
                return 'git command failing';
            }

            return 'git command ok';
        };

        $this->config
            ->method('get')
            ->willReturnMap([
                ['gitlab-domains', 0, ['gitlab.com']],
                ['github-domains', 0, ['github.com']],
            ]);

        $expectedCalls = array_fill(0, $expectedFailuresBeforeSuccess, ['cmd' => 'git command failing', 'return' => 1]);
        if ($bitbucket_git_auth_calls > 0) {
            // When we are testing what happens without auth saved, and URLs
            // with https, there will also be an attempt to find the token in
            // the git config for the folder and repo, locally.
            $additional_calls = array_fill(0, $bitbucket_git_auth_calls, ['cmd' => 'git config bitbucket.accesstoken', 'return' => 1]);
            foreach ($additional_calls as $call) {
                $expectedCalls[] = $call;
            }
        }
        $expectedCalls[] = ['cmd' => 'git command ok', 'return' => 0];

        $this->process->expects($expectedCalls, true);

        $this->io
            ->method('isInteractive')
            ->willReturn(false);

        if (null !== $bitbucketToken) {
            $this->io
                ->expects($this->atLeastOnce())
                ->method('hasAuthentication')
                ->with($this->equalTo('bitbucket.org'))
                ->willReturn(true);
            $this->io
                ->expects($this->atLeastOnce())
                ->method('getAuthentication')
                ->with($this->equalTo('bitbucket.org'))
                ->willReturn(['username' => 'token', 'password' => $bitbucketToken]);
        }
        $this->git->runCommand($commandCallable, $gitUrl, null, true);
    }

    public static function privateBitbucketWithCredentialsProvider(): array
    {
        return [
            ['git@bitbucket.org:acme/repo.git', 'MY_BITBUCKET_TOKEN', 'https://token:MY_BITBUCKET_TOKEN@bitbucket.org/acme/repo.git', 1],
            ['https://bitbucket.org/acme/repo', 'MY_BITBUCKET_TOKEN', 'https://token:MY_BITBUCKET_TOKEN@bitbucket.org/acme/repo.git', 1],
            ['https://bitbucket.org/acme/repo.git', 'MY_BITBUCKET_TOKEN', 'https://token:MY_BITBUCKET_TOKEN@bitbucket.org/acme/repo.git', 1],
            ['git@bitbucket.org:acme/repo.git', null, 'git@bitbucket.org:acme/repo.git', 0],
            ['https://bitbucket.org/acme/repo', null, 'git@bitbucket.org:acme/repo.git', 1, 1],
            ['https://bitbucket.org/acme/repo.git', null, 'git@bitbucket.org:acme/repo.git', 1, 1],
        ];
    }

    public static function privateGithubWithCredentialsProvider(): array
    {
        return [
            ['git@github.com:acme/repo.git', 'ssh', 'MY_GITHUB_TOKEN', 'https://token:MY_GITHUB_TOKEN@github.com/acme/repo.git', 1],
            ['https://github.com/acme/repo', 'https', 'MY_GITHUB_TOKEN', 'https://token:MY_GITHUB_TOKEN@github.com/acme/repo.git', 2],
        ];
    }

    private function mockConfig(string $protocol): void
    {
        $this->config
            ->method('get')
            ->willReturnMap([
                ['github-domains', 0, ['github.com']],
                ['github-protocols', 0, [$protocol]],
            ]);
    }
}
