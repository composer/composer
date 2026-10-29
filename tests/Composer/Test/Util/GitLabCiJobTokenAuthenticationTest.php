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
use Composer\Pcre\Preg;

class GitLabCiJobTokenAuthenticationTest extends TestCase
{
    private const ORIGIN = 'gitlab.example.com';
    private const JOB_TOKEN = 'job-token-value';

    /**
     * @return array<string, array{string}>
     */
    public static function urlProvider(): array
    {
        return [
            'registry: group packages.json' => ['https://gitlab.example.com/api/v4/group/123/-/packages/composer/packages.json'],
            'registry: group p2 provider' => ['https://gitlab.example.com/api/v4/group/123/-/packages/composer/p2/acme/package.json'],
            'registry: project dist archive' => ['https://gitlab.example.com/api/v4/projects/123/packages/composer/archives/acme/package.zip?sha=abc123'],
            'repository API: archive' => ['https://gitlab.example.com/api/v4/projects/acme%2Fpackage/repository/archive.zip?sha=abc123'],
            'repository API: raw file' => ['https://gitlab.example.com/api/v4/projects/acme%2Fpackage/repository/files/composer%2Ejson/raw?ref=abc123'],
            'repository API: tags' => ['https://gitlab.example.com/api/v4/projects/acme%2Fpackage/repository/tags?per_page=100'],
            'job artifacts' => ['https://gitlab.example.com/api/v4/projects/123/jobs/artifacts/main/download?job=build'],
        ];
    }

    /**
     * @dataProvider urlProvider
     */
    public function testJobTokenFromGitlabTokenConfigIsAcceptedByGitLab(string $url): void
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
            self::fakeGitLabAcceptsJobToken($url, $options['http']['header']),
            sprintf('GitLab would reject the headers sent for %s: %s', $url, implode(', ', $options['http']['header']))
        );
    }

    /**
     * Covers a mixed setup on one host: a vcs repository (which triggers GitLabDriver and thus
     * GitLab::authorizeOAuth, swapping the credential slots) plus the Composer package registry.
     *
     * @dataProvider urlProvider
     */
    public function testJobTokenAfterAuthorizeOAuthIsAcceptedByGitLab(string $url): void
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
            self::fakeGitLabAcceptsJobToken($url, $options['http']['header']),
            sprintf('GitLab would reject the headers sent for %s: %s', $url, implode(', ', $options['http']['header']))
        );
    }

    /**
     * @dataProvider urlProvider
     */
    public function testJobTokenFromHttpBasicConfigIsAcceptedByGitLab(string $url): void
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
            self::fakeGitLabAcceptsJobToken($url, $options['http']['header']),
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
     * Emulates GitLab's authentication rules for job tokens, as defined in
     * lib/api/composer_packages.rb (`job_token_allowed: :basic_auth`, basic auth only,
     * the only Composer-relevant API that works this way) and lib/api/repositories.rb
     * plus lib/api/ci/job_artifacts.rb (`job_token_allowed: true`, JOB-TOKEN header
     * only, like almost all other API routes). Any other route does not accept job
     * tokens at all.
     *
     * @param string[] $headers
     */
    private static function fakeGitLabAcceptsJobToken(string $url, array $headers): bool
    {
        if (Preg::isMatch('{/api/v4/(?:group/[^/]+/-|projects/[^/]+)/packages/composer/}i', $url)) {
            return in_array('Authorization: Basic '.base64_encode('gitlab-ci-token:'.self::JOB_TOKEN), $headers, true);
        }

        if (Preg::isMatch('{/api/v4/projects/[^/]+/(?:repository|jobs/artifacts)/}i', $url)) {
            return in_array('JOB-TOKEN: '.self::JOB_TOKEN, $headers, true);
        }

        return false;
    }
}
