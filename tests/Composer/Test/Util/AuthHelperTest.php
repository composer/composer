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

use Composer\IO\IOInterface;
use Composer\Test\TestCase;
use Composer\Util\AuthHelper;
use Composer\Util\Bitbucket;

/**
 * @author Michael Chekin <mchekin@gmail.com>
 */
class AuthHelperTest extends TestCase
{
    /** @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;

    /** @var \Composer\Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var AuthHelper */
    private $authHelper;

    protected function setUp(): void
    {
        $this->io = $this
            ->getMockBuilder('Composer\IO\IOInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->config = $this->getMockBuilder('Composer\Config')->getMock();

        $this->authHelper = new AuthHelper($this->io, $this->config);
    }

    public function testAddAuthenticationHeaderWithoutAuthCredentials(): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];
        $origin = 'http://example.org';
        $url = 'file://' . __FILE__;

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(false);

        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);

        self::assertSame($headers, $options['http']['header']);
    }

    public function testAddAuthenticationHeaderWithBearerPassword(): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];
        $origin = 'http://example.org';
        $url = 'file://' . __FILE__;
        $auth = [
            'username' => 'my_username',
            'password' => 'bearer',
        ];

        $this->expectsAuthentication($origin, $auth);

        $expectedHeaders = array_merge($headers, ['Authorization: Bearer ' . $auth['username']]);

        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);

        self::assertSame($expectedHeaders, $options['http']['header']);
    }

    public function testAddAuthenticationHeaderWithGithubToken(): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];
        $origin = 'github.com';
        $url = 'https://api.github.com/';
        $auth = [
            'username' => 'my_username',
            'password' => 'x-oauth-basic',
        ];

        $this->expectsAuthentication($origin, $auth);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using GitHub token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, ['Authorization: token ' . $auth['username']]);
        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);

        self::assertSame($expectedHeaders, $options['http']['header']);
    }

    public function testAddAuthenticationHeaderWithGitlabOathToken(): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];
        $origin = 'gitlab.com';
        $url = 'https://api.gitlab.com/';
        $auth = [
            'username' => 'my_username',
            'password' => 'oauth2',
        ];

        $this->expectsAuthentication($origin, $auth);

        $this->config->expects($this->once())
            ->method('get')
            ->with('gitlab-domains')
            ->willReturn([$origin]);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using GitLab OAuth token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, ['Authorization: Bearer ' . $auth['username']]);
        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);

        self::assertSame($expectedHeaders, $options['http']['header']);
    }

    public static function gitlabPrivateTokenProvider(): array
    {
        return [
          ['private-token'],
          ['gitlab-ci-token'],
        ];
    }

    /**
     * @dataProvider gitlabPrivateTokenProvider
     */
    public function testAddAuthenticationHeaderWithGitlabPrivateToken(string $password): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];
        $origin = 'gitlab.com';
        $url = 'https://api.gitlab.com/';
        $auth = [
            'username' => 'my_username',
            'password' => $password,
        ];

        $this->expectsAuthentication($origin, $auth);

        $this->config->expects($this->once())
            ->method('get')
            ->with('gitlab-domains')
            ->willReturn([$origin]);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using GitLab private token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, ['PRIVATE-TOKEN: ' . $auth['username']]);
        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);

        self::assertSame($expectedHeaders, $options['http']['header']);
    }

    public function testAddAuthenticationHeaderWithBitbucketOathToken(): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];
        $origin = 'bitbucket.org';
        $url = 'https://bitbucket.org/site/oauth2/authorize';
        $auth = [
            'username' => 'x-token-auth',
            'password' => 'my_password',
        ];

        $this->expectsAuthentication($origin, $auth);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using Bitbucket OAuth token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, ['Authorization: Bearer ' . $auth['password']]);
        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);

        self::assertSame($expectedHeaders, $options['http']['header']);
    }

    public static function bitbucketPublicUrlProvider(): array
    {
        return [
            ['https://bitbucket.org/user/repo/downloads/whatever'],
            ['https://bbuseruploads.s3.amazonaws.com/9421ee72-638e-43a9-82ea-39cfaae2bfaa/downloads/b87c59d9-54f3-4922-b711-d89059ec3bcf'],
        ];
    }

    /**
     * @dataProvider bitbucketPublicUrlProvider
     */
    public function testAddAuthenticationHeaderWithBitbucketPublicUrl(string $url): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];
        $origin = 'bitbucket.org';
        $auth = [
            'username' => 'x-token-auth',
            'password' => 'my_password',
        ];

        $this->expectsAuthentication($origin, $auth);

        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);
        self::assertSame($headers, $options['http']['header']);
    }

    public static function basicHttpAuthenticationProvider(): array
    {
        return [
            [
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                'bitbucket.org',
                [
                    'username' => 'x-token-auth',
                    'password' => 'my_password',
                ],
            ],
            [
                'https://some-api.url.com',
                'some-api.url.com',
                [
                    'username' => 'my_username',
                    'password' => 'my_password',
                ],
            ],
            [
                'https://gitlab.com',
                'gitlab.com',
                [
                    'username' => 'my_username',
                    'password' => 'my_password',
                ],
            ],
        ];
    }

    /**
     * @dataProvider basicHttpAuthenticationProvider
     *
     * @param array<string, string|null>                                  $auth
     *
     * @phpstan-param array{username: string|null, password: string|null} $auth
     */
    public function testAddAuthenticationHeaderWithBasicHttpAuthentication(string $url, string $origin, array $auth): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $options = ['http' => ['header' => $headers]];

        $this->expectsAuthentication($origin, $auth);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with(
                'Using HTTP basic authentication with username "' . $auth['username'] . '"',
                true,
                IOInterface::DEBUG
            );

        $expectedHeaders = array_merge(
            $headers,
            ['Authorization: Basic ' . base64_encode($auth['username'] . ':' . $auth['password'])]
        );

        $options = $this->authHelper->addAuthenticationOptions($options, $origin, $url);

        self::assertSame($expectedHeaders, $options['http']['header']);
    }

    /**
     * Tests that custom HTTP headers are correctly added to the request when using
     * the 'custom-headers' authentication type.
     */
    public function testAddAuthenticationHeaderWithCustomHeaders(): void
    {
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];
        $origin = 'example.org';
        $url = 'https://example.org/packages.json';
        $customHeaders = [
            'API-TOKEN: abc123',
            'X-CUSTOM-HEADER: value'
        ];
        $headersJson = json_encode($customHeaders);
        // Ensure we have a string, not false from json_encode failure
        $auth = [
            'username' => $headersJson !== false ? $headersJson : null,
            'password' => 'custom-headers',
        ];

        $this->expectsAuthentication($origin, $auth);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using custom HTTP headers for authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, $customHeaders);

        self::assertSame(
            $expectedHeaders,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }

    /**
     * @dataProvider bitbucketPublicUrlProvider
     */
    public function testIsPublicBitBucketDownloadWithBitbucketPublicUrl(string $url): void
    {
        self::assertTrue($this->authHelper->isPublicBitBucketDownload($url));
    }

    public function testIsPublicBitBucketDownloadWithNonBitbucketPublicUrl(): void
    {
        self::assertFalse(
            $this->authHelper->isPublicBitBucketDownload(
                'https://bitbucket.org/site/oauth2/authorize'
            )
        );
    }

    public function testStoreAuthAutomatically(): void
    {
        $origin = 'github.com';
        $storeAuth = true;
        $auth = [
            'username' => 'my_username',
            'password' => 'my_password',
        ];

        /** @var \Composer\Config\ConfigSourceInterface&\PHPUnit\Framework\MockObject\MockObject $configSource */
        $configSource = $this
            ->getMockBuilder('Composer\Config\ConfigSourceInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('getAuthConfigSource')
            ->willReturn($configSource);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($auth);

        $configSource->expects($this->once())
            ->method('addConfigSetting')
            ->with('http-basic.'.$origin, $auth);

        $this->authHelper->storeAuth($origin, $storeAuth);
    }

    public function testStoreAuthWithPromptYesAnswer(): void
    {
        $origin = 'github.com';
        $storeAuth = 'prompt';
        $auth = [
            'username' => 'my_username',
            'password' => 'my_password',
        ];
        $answer = 'y';
        $configSourceName = 'https://api.gitlab.com/source';

        /** @var \Composer\Config\ConfigSourceInterface&\PHPUnit\Framework\MockObject\MockObject $configSource */
        $configSource = $this
            ->getMockBuilder('Composer\Config\ConfigSourceInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('getAuthConfigSource')
            ->willReturn($configSource);

        $configSource->expects($this->once())
            ->method('getName')
            ->willReturn($configSourceName);

        $this->io->expects($this->once())
            ->method('askAndValidate')
            ->with(
                'Do you want to store credentials for '.$origin.' in '.$configSourceName.' ? [Yn] ',
                $this->anything(),
                null,
                'y'
            )
            ->willReturnCallback(static function ($question, $validator, $attempts, $default) use ($answer): string {
                $validator($answer);

                return $answer;
            });

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($auth);

        $configSource->expects($this->once())
            ->method('addConfigSetting')
            ->with('http-basic.'.$origin, $auth);

        $this->authHelper->storeAuth($origin, $storeAuth);
    }

    public function testStoreAuthWithPromptNoAnswer(): void
    {
        $origin = 'github.com';
        $storeAuth = 'prompt';
        $answer = 'n';
        $configSourceName = 'https://api.gitlab.com/source';

        /** @var \Composer\Config\ConfigSourceInterface&\PHPUnit\Framework\MockObject\MockObject $configSource */
        $configSource = $this
            ->getMockBuilder('Composer\Config\ConfigSourceInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('getAuthConfigSource')
            ->willReturn($configSource);

        $configSource->expects($this->once())
            ->method('getName')
            ->willReturn($configSourceName);

        $this->io->expects($this->once())
            ->method('askAndValidate')
            ->with(
                'Do you want to store credentials for '.$origin.' in '.$configSourceName.' ? [Yn] ',
                $this->anything(),
                null,
                'y'
            )
            ->willReturnCallback(static function ($question, $validator, $attempts, $default) use ($answer): string {
                $validator($answer);

                return $answer;
            });

        $this->authHelper->storeAuth($origin, $storeAuth);
    }

    public function testStoreAuthWithPromptInvalidAnswer(): void
    {
        self::expectException('RuntimeException');

        $origin = 'github.com';
        $storeAuth = 'prompt';
        $answer = 'invalid';
        $configSourceName = 'https://api.gitlab.com/source';

        /** @var \Composer\Config\ConfigSourceInterface&\PHPUnit\Framework\MockObject\MockObject $configSource */
        $configSource = $this
            ->getMockBuilder('Composer\Config\ConfigSourceInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->config->expects($this->once())
            ->method('getAuthConfigSource')
            ->willReturn($configSource);

        $configSource->expects($this->once())
            ->method('getName')
            ->willReturn($configSourceName);

        $this->io->expects($this->once())
            ->method('askAndValidate')
            ->with(
                'Do you want to store credentials for '.$origin.' in '.$configSourceName.' ? [Yn] ',
                $this->anything(),
                null,
                'y'
            )
            ->willReturnCallback(static function ($question, $validator, $attempts, $default) use ($answer): string {
                $validator($answer);

                return $answer;
            });

        $this->authHelper->storeAuth($origin, $storeAuth);
    }

    public function testPromptAuthIfNeededGitLabNoAuthChange(): void
    {
        self::expectException('Composer\Downloader\TransportException');

        $origin = 'gitlab.com';

        $this->io
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn([
                'username' => 'gitlab-user',
                'password' => 'gitlab-password',
            ]);

        $this->io
            ->expects($this->once())
            ->method('setAuthentication')
            ->with('gitlab.com', 'gitlab-user', 'gitlab-password');

        $this->config
            ->method('get')
            ->willReturnMap([
                ['github-domains', 0, []],
                ['gitlab-domains', 0, ['gitlab.com']],
                ['gitlab-token', 0, ['gitlab.com' => ['username' => 'gitlab-user', 'token' => 'gitlab-password']]],
            ]);

        $this->authHelper->promptAuthIfNeeded('https://gitlab.com/acme/archive.zip', $origin, 404, 'GitLab requires authentication and it was not provided');
    }

    public function testPromptAuthIfNeededMultipleBitbucketDownloads(): void
    {
        $origin = 'bitbucket.org';

        $expectedResult = [
            'retry' => true,
            'storeAuth' => false,
        ];

        $authConfig = [
            'bitbucket.org' => [
                'access-token' => 'bitbucket_access_token',
                'access-token-expiration' => time() + 1800,
            ]
        ];

        $this->config
            ->method('get')
            ->willReturnMap([
                ['github-domains', 0, []],
                ['gitlab-domains', 0, []],
                ['bitbucket-oauth', 0, $authConfig],
                ['github-domains', 0, []],
                ['gitlab-domains', 0, []],
            ]);

        $this->io
            ->expects($this->exactly(2))
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $getAuthenticationReturnValues = [
            ['username' => 'bitbucket_client_id', 'password' => 'bitbucket_client_secret'],
            ['username' => 'x-token-auth', 'password' => 'bitbucket_access_token'],
        ];

        $this->io
            ->expects($this->exactly(2))
            ->method('getAuthentication')
            ->willReturnCallback(
                function ($repositoryName) use (&$getAuthenticationReturnValues) {
                    return array_shift($getAuthenticationReturnValues);
                }
            );

        $this->io
            ->expects($this->once())
            ->method('setAuthentication')
            ->with($origin, 'x-token-auth', 'bitbucket_access_token');

        $result1 = $this->authHelper->promptAuthIfNeeded('https://bitbucket.org/workspace/repo1/get/hash1.zip', $origin, 401, 'HTTP/2 401 ');
        $result2 = $this->authHelper->promptAuthIfNeeded('https://bitbucket.org/workspace/repo2/get/hash2.zip', $origin, 401, 'HTTP/2 401 ');

        self::assertSame(
            $expectedResult,
            $result1
        );

        self::assertSame(
            $expectedResult,
            $result2
        );
    }

    /**
     * @dataProvider basicHttpAuthenticationProvider
     * @param array<string, string|null>                                  $auth
     * @phpstan-param array{username: string|null, password: string|null} $auth
     */
    public function testAddAuthenticationHeaderIsWorking(string $url, string $origin, array $auth): void
    {
        set_error_handler(
            static function (): bool {
                return true;
            },
            E_USER_DEPRECATED
        );

        $this->expectsAuthentication($origin, $auth);
        $headers = [
            'Accept-Encoding: gzip',
            'Connection: close',
        ];

        $this->expectsAuthentication($origin, $auth);

        try {
            $updatedHeaders = $this->authHelper->addAuthenticationHeader($headers, $origin, $url);
        } finally {
            restore_error_handler();
        }
        $this->assertIsArray($updatedHeaders);


    }

    public function testAddAuthenticationHeaderDeprecation(): void
    {
        set_error_handler(
            static function (int $errno, string $errstr) {
                throw new \RuntimeException($errstr);
            },
            E_USER_DEPRECATED
        );

        $headers = [];
        $origin  = 'example.org';
        $url     = 'file://' . __FILE__;


        $expectedException = new \RuntimeException('AuthHelper::addAuthenticationHeader is deprecated since Composer 2.9 use addAuthenticationOptions instead.');
        $this->expectExceptionObject($expectedException);
        try {
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url);
        } finally {
            restore_error_handler();
        }
    }
    /**
     * @param array<string, string|null> $auth
     *
     * @phpstan-param array{username: string|null, password: string|null} $auth
     */
    private function expectsAuthentication(string $origin, array $auth): void
    {
        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($auth);
    }
}
