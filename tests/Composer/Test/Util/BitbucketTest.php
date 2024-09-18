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

use Composer\Test\Mock\IOMock;
use Composer\Util\Bitbucket;
use Composer\Util\Http\Response;
use Composer\Test\TestCase;

/**
 * @author Paul Wenke <wenke.paul@gmail.com>
 */
class BitbucketTest extends TestCase
{
    /** @var string */
    private $username = 'username';
    /** @var string */
    private $password = 'password';
    /** @var string */
    private $consumer_key = 'consumer_key';
    /** @var string */
    private $consumer_secret = 'consumer_secret';
    /** @var string */
    private $message = 'mymessage';
    /** @var string */
    private $origin = 'bitbucket.org';
    /** @var string */
    private $token = 'bitbuckettoken';

    /** @var IOMock */
    private $io;
    /** @var \Composer\Util\HttpDownloader&\PHPUnit\Framework\MockObject\MockObject */
    private $httpDownloader;
    /** @var \Composer\Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var Bitbucket */
    private $bitbucket;
    /** @var int */
    private $time;

    protected function setUp(): void
    {
        $this->io = $this->getIOMock();

        $this->httpDownloader = $this
            ->getMockBuilder('Composer\Util\HttpDownloader')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->config = $this->getMockBuilder('Composer\Config')->getMock();

        $this->time = time();

        $this->bitbucket = new Bitbucket($this->io, $this->config, null, $this->httpDownloader, $this->time);
    }

    public function testRequestAccessTokenWithValidOAuthConsumer(): void
    {
        $this->io->expects([
            ['auth' => [$this->origin, $this->consumer_key, $this->consumer_secret]],
        ]);

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                [
                    'retry-auth-failure' => false,
                    'http' => [
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ],
                ]
            )
            ->willReturn(
                new Response(
                    ['url' => Bitbucket::OAUTH2_ACCESS_TOKEN_URL],
                    200,
                    [],
                    sprintf(
                        '{"access_token": "%s", "scopes": "repository", "expires_in": 3600, "refresh_token": "refreshtoken", "token_type": "bearer"}',
                        $this->token
                    )
                )
            );

        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(null);

        $this->setExpectationsForStoringAccessToken();

        self::assertEquals(
            $this->token,
            $this->bitbucket->requestToken($this->origin, $this->consumer_key, $this->consumer_secret)
        );
    }

    public function testRequestAccessTokenWithValidOAuthConsumerAndValidStoredAccessToken(): Bitbucket
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(
                [
                    $this->origin => [
                        'access-token' => $this->token,
                        'access-token-expiration' => $this->time + 1800,
                        'consumer-key' => $this->consumer_key,
                        'consumer-secret' => $this->consumer_secret,
                    ],
                ]
            );

        self::assertEquals(
            $this->token,
            $this->bitbucket->requestToken($this->origin, $this->consumer_key, $this->consumer_secret)
        );

        return $this->bitbucket;
    }

    public function testRequestAccessTokenWithValidOAuthConsumerAndExpiredAccessToken(): void
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(
                [
                    $this->origin => [
                        'access-token' => 'randomExpiredToken',
                        'access-token-expiration' => $this->time - 400,
                        'consumer-key' => $this->consumer_key,
                        'consumer-secret' => $this->consumer_secret,
                    ],
                ]
            );

        $this->io->expects([
            ['auth' => [$this->origin, $this->consumer_key, $this->consumer_secret]],
        ]);

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                [
                    'retry-auth-failure' => false,
                    'http' => [
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ],
                ]
            )
            ->willReturn(
                new Response(
                    ['url' => Bitbucket::OAUTH2_ACCESS_TOKEN_URL],
                    200,
                    [],
                    sprintf(
                        '{"access_token": "%s", "scopes": "repository", "expires_in": 3600, "refresh_token": "refreshtoken", "token_type": "bearer"}',
                        $this->token
                    )
                )
            );

        $this->setExpectationsForStoringAccessToken();

        self::assertEquals(
            $this->token,
            $this->bitbucket->requestToken($this->origin, $this->consumer_key, $this->consumer_secret)
        );
    }

    public function testRequestAccessTokenWithUsernameAndPassword(): void
    {
        $this->io->expects([
            ['auth' => [$this->origin, $this->username, $this->password]],
            ['text' => 'Invalid OAuth consumer provided.'],
            ['text' => 'This can have three reasons:'],
            ['text' => '1. You are authenticating with a bitbucket username/password combination'],
            ['text' => '2. You are using an OAuth consumer, but didn\'t configure a (dummy) callback url'],
            ['text' => '3. You are using an OAuth consumer, but didn\'t configure it as private consumer'],
        ], true);

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                [
                    'retry-auth-failure' => false,
                    'http' => [
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ],
                ]
            )
            ->willThrowException(
                new \Composer\Downloader\TransportException(
                    sprintf(
                        'The \'%s\' URL could not be accessed: HTTP/1.1 400 BAD REQUEST',
                        Bitbucket::OAUTH2_ACCESS_TOKEN_URL
                    ),
                    400
                )
            );

        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(null);

        self::assertEquals('', $this->bitbucket->requestToken($this->origin, $this->username, $this->password));
    }

    public function testRequestAccessTokenWithUsernameAndPasswordWithUnauthorizedResponse(): void
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(null);

        $this->io->expects([
            ['auth' => [$this->origin, $this->username, $this->password]],
            ['text' => 'Invalid OAuth consumer provided.'],
            ['text' => 'You can also add it manually later by using "composer config --global --auth bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"'],
        ], true);

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                [
                    'retry-auth-failure' => false,
                    'http' => [
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ],
                ]
            )
            ->willThrowException(new \Composer\Downloader\TransportException('HTTP/1.1 401 UNAUTHORIZED', 401));

        self::assertEquals('', $this->bitbucket->requestToken($this->origin, $this->username, $this->password));
    }

    public function testRequestAccessTokenWithUsernameAndPasswordWithNotFoundResponse(): void
    {
        self::expectException('Composer\Downloader\TransportException');
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(null);

        $this->io->expects([
            ['auth' => [$this->origin, $this->username, $this->password]],
        ]);

        $exception = new \Composer\Downloader\TransportException('HTTP/1.1 404 NOT FOUND', 404);
        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                [
                    'retry-auth-failure' => false,
                    'http' => [
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ],
                ]
            )
            ->willThrowException($exception);

        $this->bitbucket->requestToken($this->origin, $this->username, $this->password);
    }

    public function testUsernamePasswordAuthenticationFlow(): void
    {
        $this->io->expects([
            ['text' => $this->message],
            ['ask' => 'Consumer Key (hidden): ', 'reply' => $this->consumer_key],
            ['ask' => 'Consumer Secret (hidden): ', 'reply' => $this->consumer_secret],
        ]);

        $this->httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url = sprintf('https://%s/site/oauth2/access_token', $this->origin)),
                $this->anything()
            )
            ->willReturn(
                new Response(
                    ['url' => $url],
                    200,
                    [],
                    sprintf(
                        '{"access_token": "%s", "scopes": "repository", "expires_in": 3600, "refresh_token": "refresh_token", "token_type": "bearer"}',
                        $this->token
                    )
                )
            )
        ;

        $this->setExpectationsForStoringAccessToken(true);

        self::assertTrue($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    public function testAuthorizeOAuthInteractivelyWithEmptyUsername(): void
    {
        $authConfigSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($authConfigSourceMock);

        $this->io->expects([
            ['ask' => 'Consumer Key (hidden): ', 'reply' => ''],
        ]);

        self::assertFalse($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    public function testAuthorizeOAuthInteractivelyWithEmptyPassword(): void
    {
        $authConfigSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($authConfigSourceMock);

        $this->io->expects([
            ['text' => $this->message],
            ['ask' => 'Consumer Key (hidden): ', 'reply' => $this->consumer_key],
            ['ask' => 'Consumer Secret (hidden): ', 'reply' => ''],
        ]);

        self::assertFalse($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    public function testAuthorizeOAuthInteractivelyWithRequestAccessTokenFailure(): void
    {
        $authConfigSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($authConfigSourceMock);

        $this->io->expects([
            ['text' => $this->message],
            ['ask' => 'Consumer Key (hidden): ', 'reply' => $this->consumer_key],
            ['ask' => 'Consumer Secret (hidden): ', 'reply' => $this->consumer_secret],
        ]);

        $this->httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url = sprintf('https://%s/site/oauth2/access_token', $this->origin)),
                $this->anything()
            )
            ->willThrowException(
                new \Composer\Downloader\TransportException(
                    sprintf(
                        'The \'%s\' URL could not be accessed: HTTP/1.1 400 BAD REQUEST',
                        Bitbucket::OAUTH2_ACCESS_TOKEN_URL
                    ),
                    400
                )
            );

        self::assertFalse($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    private function setExpectationsForStoringAccessToken(bool $removeBasicAuth = false): void
    {
        $configSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->once())
            ->method('getConfigSource')
            ->willReturn($configSourceMock);

        $configSourceMock->expects($this->once())
            ->method('removeConfigSetting')
            ->with('bitbucket-oauth.' . $this->origin);

        $authConfigSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($authConfigSourceMock);

        $authConfigSourceMock->expects($this->once())
            ->method('addConfigSetting')
            ->with(
                'bitbucket-oauth.' . $this->origin,
                [
                    "consumer-key" => $this->consumer_key,
                    "consumer-secret" => $this->consumer_secret,
                    "access-token" => $this->token,
                    "access-token-expiration" => $this->time + 3600,
                ]
            );

        if ($removeBasicAuth) {
            $authConfigSourceMock->expects($this->once())
                ->method('removeConfigSetting')
                ->with('http-basic.' . $this->origin);
        }
    }

    public function testGetTokenWithoutAccessToken(): void
    {
        self::assertSame('', $this->bitbucket->getToken());
    }

    /**
     * @depends testRequestAccessTokenWithValidOAuthConsumerAndValidStoredAccessToken
     */
    public function testGetTokenWithAccessToken(Bitbucket $bitbucket): void
    {
        self::assertSame($this->token, $bitbucket->getToken());
    }

    public function testAuthorizeOAuthWithWrongOriginUrl(): void
    {
        self::assertFalse($this->bitbucket->authorizeOAuth('non-' . $this->origin));
    }

    public function testAuthorizeOAuthWithoutAvailableGitConfigToken(): void
    {
        $process = $this->getProcessExecutorMock();
        $process->expects([], false, ['return' => -1]);

        $bitbucket = new Bitbucket($this->io, $this->config, $process, $this->httpDownloader, $this->time);

        self::assertFalse($bitbucket->authorizeOAuth($this->origin));
    }

    public function testAuthorizeOAuthWithAvailableGitConfigToken(): void
    {
        $process = $this->getProcessExecutorMock();

        $bitbucket = new Bitbucket($this->io, $this->config, $process, $this->httpDownloader, $this->time);

        self::assertTrue($bitbucket->authorizeOAuth($this->origin));
    }
}
