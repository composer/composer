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
use Composer\Test\Mock\HttpDownloaderMock;
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

        // @phpstan-ignore method.deprecated
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
            ['cmd' => ['git', '--version'], 'return' => 0],
        ], true);

        // @phpstan-ignore method.deprecated
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

        // @phpstan-ignore method.deprecated
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
            $additional_calls = array_fill(0, $bitbucket_git_auth_calls, ['cmd' => ['git', 'config', 'bitbucket.accesstoken'], 'return' => 1]);
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
        // @phpstan-ignore method.deprecated
        $this->git->runCommand($commandCallable, $gitUrl, null, true);
    }

    /**
     * @dataProvider privateBitbucketWithOauthProvider
     *
     * @param string $gitUrl
     * @param string $expectedUrl
     * @param array{'username': string, 'password': string}[] $initial_config
     */
    public function testRunCommandPrivateBitbucketRepositoryNotInitialCloneInteractiveWithOauth(string $gitUrl, string $expectedUrl, array $initial_config = []): void
    {
        $commandCallable = static function ($url) use ($expectedUrl): string {
            if ($url !== $expectedUrl) {
                return 'git command failing';
            }

            return 'git command ok';
        };

        $expectedCalls = [];
        $expectedCalls[] = ['cmd' => 'git command failing', 'return' => 1];
        if (count($initial_config) > 0) {
            $expectedCalls[] = ['cmd' => 'git command failing', 'return' => 1];
        } else {
            $expectedCalls[] = ['cmd' => ['git', 'config', 'bitbucket.accesstoken'], 'return' => 1];
        }
        $expectedCalls[] = ['cmd' => 'git command ok', 'return' => 0];
        $this->process->expects($expectedCalls, true);

        $this->config
            ->method('get')
            ->willReturnMap([
                ['gitlab-domains', 0, ['gitlab.com']],
                ['github-domains', 0, ['github.com']],
            ]);

        $this->io
            ->method('isInteractive')
            ->willReturn(true);

        $this->io
            ->method('askConfirmation')
            ->willReturnCallback(function () {
               return true;
            });
        $this->io->method('askAndHideAnswer')
            ->willReturnCallback(function ($question) {
                switch ($question) {
                    case 'Consumer Key (hidden): ':
                        return 'my-consumer-key';
                    case 'Consumer Secret (hidden): ':
                        return 'my-consumer-secret';
                }
                return '';
            });

        $this->io
            ->method('hasAuthentication')
            ->with($this->equalTo('bitbucket.org'))
            ->willReturnCallback(function ($repositoryName) use (&$initial_config) {
                return isset($initial_config[$repositoryName]);
            });
        $this->io
            ->method('setAuthentication')
            ->willReturnCallback(function (string $repositoryName, string $username, ?string $password = null) use (&$initial_config) {
                $initial_config[$repositoryName] = ['username' => $username, 'password' => $password];
            });
        $this->io
            ->method('getAuthentication')
            ->willReturnCallback(function (string $repositoryName) use (&$initial_config) {
                if (isset($initial_config[$repositoryName])) {
                    return $initial_config[$repositoryName];
                }

                return ['username' => null, 'password' => null];
            });
        $downloader_mock = $this->getHttpDownloaderMock();
        $downloader_mock->expects([
            ['url' => 'https://bitbucket.org/site/oauth2/access_token', 'status' => 200, 'body' => '{"expires_in": 600, "access_token": "my-access-token"}']
        ]);
        $this->git->setHttpDownloader($downloader_mock);
        // @phpstan-ignore method.deprecated
        $this->git->runCommand($commandCallable, $gitUrl, null, true);
    }

    public static function privateBitbucketWithOauthProvider(): array
    {
        return [
            ['git@bitbucket.org:acme/repo.git', 'https://x-token-auth:my-access-token@bitbucket.org/acme/repo.git'],
            ['https://bitbucket.org/acme/repo.git', 'https://x-token-auth:my-access-token@bitbucket.org/acme/repo.git'],
            ['https://bitbucket.org/acme/repo', 'https://x-token-auth:my-access-token@bitbucket.org/acme/repo.git'],
            ['git@bitbucket.org:acme/repo.git', 'https://x-token-auth:my-access-token@bitbucket.org/acme/repo.git', ['bitbucket.org' => ['username' => 'someuseralsoswappedfortoken', 'password' => 'little green men']]],
        ];
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
            ['https://bitbucket.org/acme/repo.git', 'ATAT_BITBUCKET_API_TOKEN', 'https://x-bitbucket-api-token-auth:ATAT_BITBUCKET_API_TOKEN@bitbucket.org/acme/repo.git', 1],
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
