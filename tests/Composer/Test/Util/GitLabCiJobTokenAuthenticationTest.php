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
use Composer\IO\BufferIO;
use Composer\Test\TestCase;
use Composer\Util\AuthHelper;
use Composer\Util\GitLab;

class GitLabCiJobTokenAuthenticationTest extends TestCase
{
    private const ORIGIN = 'gitlab.example.com';
    private const JOB_TOKEN = 'job-token-value';

    private const BASIC_AUTH = 'basic';
    private const JOB_TOKEN_HEADER = 'header';

    /**
     * @return array<string, array{string, self::BASIC_AUTH|self::JOB_TOKEN_HEADER}>
     */
    public static function urlProvider(): array
    {
        return [
            'registry: group packages.json' => ['https://gitlab.example.com/api/v4/group/123/-/packages/composer/packages.json', self::BASIC_AUTH],
            'registry: group p2 provider' => ['https://gitlab.example.com/api/v4/group/123/-/packages/composer/p2/acme/package.json', self::BASIC_AUTH],
            'registry: project dist archive' => ['https://gitlab.example.com/api/v4/projects/123/packages/composer/archives/acme/package.zip?sha=abc123', self::BASIC_AUTH],
            'repository API: archive' => ['https://gitlab.example.com/api/v4/projects/acme%2Fpackage/repository/archive.zip?sha=abc123', self::JOB_TOKEN_HEADER],
            'repository API: raw file' => ['https://gitlab.example.com/api/v4/projects/acme%2Fpackage/repository/files/composer%2Ejson/raw?ref=abc123', self::JOB_TOKEN_HEADER],
            'repository API: tags' => ['https://gitlab.example.com/api/v4/projects/acme%2Fpackage/repository/tags?per_page=100', self::JOB_TOKEN_HEADER],
            'job artifacts' => ['https://gitlab.example.com/api/v4/projects/123/jobs/artifacts/main/download?job=build', self::JOB_TOKEN_HEADER],
        ];
    }

    /**
     * @dataProvider urlProvider
     */
    public function testJobTokenFromGitlabTokenConfigIsAcceptedByGitLab(string $url, string $acceptedAuth): void
    {
        $config = self::createConfig([
            'gitlab-token' => [
                self::ORIGIN => ['username' => 'gitlab-ci-token', 'token' => self::JOB_TOKEN],
            ],
        ]);
        $io = new BufferIO();
        $io->loadConfiguration($config);
        $authHelper = new AuthHelper($io, $config);

        $options = $authHelper->addAuthenticationOptions([], self::ORIGIN, $url);

        self::assertTrue(
            self::fakeGitLabAcceptsJobToken($acceptedAuth, $options['http']['header']),
            sprintf('GitLab would reject the headers sent for %s: %s', $url, implode(', ', $options['http']['header']))
        );
    }

    /**
     * Mixed vcs + registry setup on one host, where GitLab::authorizeOAuth swaps the credential slots
     *
     * @dataProvider urlProvider
     */
    public function testJobTokenAfterAuthorizeOAuthIsAcceptedByGitLab(string $url, string $acceptedAuth): void
    {
        $config = self::createConfig([
            'gitlab-token' => [
                self::ORIGIN => ['username' => 'gitlab-ci-token', 'token' => self::JOB_TOKEN],
            ],
        ]);
        $io = new BufferIO();
        $io->loadConfiguration($config);
        $authHelper = new AuthHelper($io, $config);

        $process = $this->getProcessExecutorMock();
        $process->expects([
            ['cmd' => ['git', 'config', 'gitlab.accesstoken'], 'return' => 1],
            ['cmd' => ['git', 'config', 'gitlab.deploytoken.user'], 'return' => 1],
        ]);

        $gitLab = new GitLab($io, $config, $process);
        self::assertTrue($gitLab->authorizeOAuth(self::ORIGIN));

        $auth = $io->getAuthentication(self::ORIGIN);
        self::assertSame('gitlab-ci-token', $auth['password'], 'authorizeOAuth should have swapped the credential slots');

        $options = $authHelper->addAuthenticationOptions([], self::ORIGIN, $url);

        self::assertTrue(
            self::fakeGitLabAcceptsJobToken($acceptedAuth, $options['http']['header']),
            sprintf('GitLab would reject the headers sent for %s: %s', $url, implode(', ', $options['http']['header']))
        );
    }

    /**
     * @dataProvider urlProvider
     */
    public function testJobTokenFromHttpBasicConfigIsAcceptedByGitLab(string $url, string $acceptedAuth): void
    {
        $config = self::createConfig([
            'http-basic' => [
                self::ORIGIN => ['username' => 'gitlab-ci-token', 'password' => self::JOB_TOKEN],
            ],
        ]);
        $io = new BufferIO();
        $io->loadConfiguration($config);
        $authHelper = new AuthHelper($io, $config);

        $options = $authHelper->addAuthenticationOptions([], self::ORIGIN, $url);

        self::assertTrue(
            self::fakeGitLabAcceptsJobToken($acceptedAuth, $options['http']['header']),
            sprintf('GitLab would reject the headers sent for %s: %s', $url, implode(', ', $options['http']['header']))
        );
    }

    /**
     * A plain string gitlab-token is a personal access token and must keep using PRIVATE-TOKEN.
     */
    public function testPersonalAccessTokenStillUsesPrivateTokenHeader(): void
    {
        $config = self::createConfig([
            'gitlab-token' => [
                self::ORIGIN => 'pat-value',
            ],
        ]);
        $io = new BufferIO();
        $io->loadConfiguration($config);
        $authHelper = new AuthHelper($io, $config);

        foreach ([
            'https://gitlab.example.com/api/v4/group/123/-/packages/composer/packages.json',
            'https://gitlab.example.com/api/v4/projects/acme%2Fpackage/repository/archive.zip?sha=abc123',
        ] as $url) {
            $options = $authHelper->addAuthenticationOptions([], self::ORIGIN, $url);

            self::assertContains('PRIVATE-TOKEN: pat-value', $options['http']['header']);
        }
    }

    /**
     * @param array<string, mixed> $authConfig
     */
    private static function createConfig(array $authConfig): Config
    {
        $config = new Config(false);
        $config->merge(['config' => array_merge(['gitlab-domains' => [self::ORIGIN]], $authConfig)]);

        return $config;
    }

    /**
     * GitLab accepts job tokens only via basic auth on the Composer registry (lib/api/composer_packages.rb)
     * and only via the JOB-TOKEN header on the repository and job artifacts APIs.
     *
     * @param string[] $headers
     */
    private static function fakeGitLabAcceptsJobToken(string $acceptedAuth, array $headers): bool
    {
        if ($acceptedAuth === self::BASIC_AUTH) {
            return in_array('Authorization: Basic '.base64_encode('gitlab-ci-token:'.self::JOB_TOKEN), $headers, true);
        }

        return in_array('JOB-TOKEN: '.self::JOB_TOKEN, $headers, true);
    }
}
