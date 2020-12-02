<?php

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

use Composer\Util\Bitbucket;
use Composer\Util\Http\Response;
use Composer\Test\TestCase;

/**
 * @author Paul Wenke <wenke.paul@gmail.com>
 */
class BitbucketTest extends TestCase
{
    private $username = 'username';
    private $password = 'password';
    private $consumer_key = 'consumer_key';
    private $consumer_secret = 'consumer_secret';
    private $message = 'mymessage';
    private $origin = 'bitbucket.org';
    private $token = 'bitbuckettoken';

    /** @type \Composer\IO\ConsoleIO|\PHPUnit_Framework_MockObject_MockObject */
    private $io;
    /** @type \Composer\Util\HttpDownloader|\PHPUnit_Framework_MockObject_MockObject */
    private $httpDownloader;
    /** @type \Composer\Config|\PHPUnit_Framework_MockObject_MockObject */
    private $config;
    /** @type Bitbucket */
    private $bitbucket;
    /** @var int */
    private $time;

    protected function setUp()
    {
        $this->io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->httpDownloader = $this
            ->getMockBuilder('Composer\Util\HttpDownloader')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->config = $this->getMockBuilder('Composer\Config')->getMock();

        $this->time = time();

        $this->bitbucket = new Bitbucket($this->io, $this->config, null, $this->httpDownloader, $this->time);
    }

    public function testRequestAccessTokenWithValidOAuthConsumer()
    {
        $this->io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->origin, $this->consumer_key, $this->consumer_secret);

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                array(
                    'retry-auth-failure' => false,
                    'http' => array(
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ),
                )
            )
            ->willReturn(
                new Response(
                    array('url' => Bitbucket::OAUTH2_ACCESS_TOKEN_URL),
                    200,
                    array(),
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

        $this->assertEquals(
            $this->token,
            $this->bitbucket->requestToken($this->origin, $this->consumer_key, $this->consumer_secret)
        );
    }

    public function testRequestAccessTokenWithValidOAuthConsumerAndValidStoredAccessToken()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(
                array(
                    $this->origin => array(
                        'access-token' => $this->token,
                        'access-token-expiration' => $this->time + 1800,
                        'consumer-key' => $this->consumer_key,
                        'consumer-secret' => $this->consumer_secret,
                    ),
                )
            );

        $this->assertEquals(
            $this->token,
            $this->bitbucket->requestToken($this->origin, $this->consumer_key, $this->consumer_secret)
        );

        return $this->bitbucket;
    }

    public function testRequestAccessTokenWithValidOAuthConsumerAndExpiredAccessToken()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(
                array(
                    $this->origin => array(
                        'access-token' => 'randomExpiredToken',
                        'access-token-expiration' => $this->time - 400,
                        'consumer-key' => $this->consumer_key,
                        'consumer-secret' => $this->consumer_secret,
                    ),
                )
            );

        $this->io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->origin, $this->consumer_key, $this->consumer_secret);

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                array(
                    'retry-auth-failure' => false,
                    'http' => array(
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ),
                )
            )
            ->willReturn(
                new Response(
                    array('url' => Bitbucket::OAUTH2_ACCESS_TOKEN_URL),
                    200,
                    array(),
                    sprintf(
                        '{"access_token": "%s", "scopes": "repository", "expires_in": 3600, "refresh_token": "refreshtoken", "token_type": "bearer"}',
                        $this->token
                    )
                )
            );

        $this->setExpectationsForStoringAccessToken();

        $this->assertEquals(
            $this->token,
            $this->bitbucket->requestToken($this->origin, $this->consumer_key, $this->consumer_secret)
        );
    }

    public function testRequestAccessTokenWithUsernameAndPassword()
    {
        $this->io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->origin, $this->username, $this->password);

        $this->io->expects($this->any())
            ->method('writeError')
            ->withConsecutive(
                array('<error>Invalid OAuth consumer provided.</error>'),
                array('This can have two reasons:'),
                array('1. You are authenticating with a bitbucket username/password combination'),
                array('2. You are using an OAuth consumer, but didn\'t configure a (dummy) callback url')
            );

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                array(
                    'retry-auth-failure' => false,
                    'http' => array(
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ),
                )
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

        $this->assertEquals('', $this->bitbucket->requestToken($this->origin, $this->username, $this->password));
    }

    public function testRequestAccessTokenWithUsernameAndPasswordWithUnauthorizedResponse()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(null);

        $this->io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->origin, $this->username, $this->password);

        $this->io->expects($this->any())
            ->method('writeError')
            ->withConsecutive(
                array('<error>Invalid OAuth consumer provided.</error>'),
                array('You can also add it manually later by using "composer config --global --auth bitbucket-oauth.bitbucket.org <consumer-key> <consumer-secret>"')
            );

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                array(
                    'retry-auth-failure' => false,
                    'http' => array(
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ),
                )
            )
            ->willThrowException(new \Composer\Downloader\TransportException('HTTP/1.1 401 UNAUTHORIZED', 401));

        $this->assertEquals('', $this->bitbucket->requestToken($this->origin, $this->username, $this->password));
    }

    public function testRequestAccessTokenWithUsernameAndPasswordWithNotFoundResponse()
    {
        $this->setExpectedException('Composer\Downloader\TransportException');
        $this->config->expects($this->once())
            ->method('get')
            ->with('bitbucket-oauth')
            ->willReturn(null);

        $this->io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->origin, $this->username, $this->password);

        $exception = new \Composer\Downloader\TransportException('HTTP/1.1 404 NOT FOUND', 404);
        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                array(
                    'retry-auth-failure' => false,
                    'http' => array(
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    ),
                )
            )
            ->willThrowException($exception);

        $this->bitbucket->requestToken($this->origin, $this->username, $this->password);
    }

    public function testUsernamePasswordAuthenticationFlow()
    {
        $this->io
            ->expects($this->at(0))
            ->method('writeError')
            ->with($this->message)
        ;

        $this->io->expects($this->exactly(2))
            ->method('askAndHideAnswer')
            ->withConsecutive(
                array('Consumer Key (hidden): '),
                array('Consumer Secret (hidden): ')
            )
            ->willReturnOnConsecutiveCalls($this->consumer_key, $this->consumer_secret);

        $this->httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url = sprintf('https://%s/site/oauth2/access_token', $this->origin)),
                $this->anything()
            )
            ->willReturn(
                new Response(
                    array('url' => $url),
                    200,
                    array(),
                    sprintf(
                        '{"access_token": "%s", "scopes": "repository", "expires_in": 3600, "refresh_token": "refresh_token", "token_type": "bearer"}',
                        $this->token
                    )
                )
            )
        ;

        $this->setExpectationsForStoringAccessToken(true);

        $this->assertTrue($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    public function testAuthorizeOAuthInteractivelyWithEmptyUsername()
    {
        $authConfigSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($authConfigSourceMock);

        $this->io->expects($this->once())
            ->method('askAndHideAnswer')
            ->with('Consumer Key (hidden): ')
            ->willReturnOnConsecutiveCalls(null);

        $this->assertFalse($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    public function testAuthorizeOAuthInteractivelyWithEmptyPassword()
    {
        $authConfigSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($authConfigSourceMock);

        $this->io->expects($this->exactly(2))
            ->method('askAndHideAnswer')
            ->withConsecutive(
                array('Consumer Key (hidden): '),
                array('Consumer Secret (hidden): ')
            )
            ->willReturnOnConsecutiveCalls($this->consumer_key, null);

        $this->assertFalse($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    public function testAuthorizeOAuthInteractivelyWithRequestAccessTokenFailure()
    {
        $authConfigSourceMock = $this->getMockBuilder('Composer\Config\ConfigSourceInterface')->getMock();
        $this->config->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($authConfigSourceMock);

        $this->io->expects($this->exactly(2))
            ->method('askAndHideAnswer')
            ->withConsecutive(
                array('Consumer Key (hidden): '),
                array('Consumer Secret (hidden): ')
            )
            ->willReturnOnConsecutiveCalls($this->consumer_key, $this->consumer_secret);

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

        $this->assertFalse($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    private function setExpectationsForStoringAccessToken($removeBasicAuth = false)
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
                array(
                    "consumer-key" => $this->consumer_key,
                    "consumer-secret" => $this->consumer_secret,
                    "access-token" => $this->token,
                    "access-token-expiration" => $this->time + 3600,
                )
            );

        if ($removeBasicAuth) {
            $authConfigSourceMock->expects($this->once())
                ->method('removeConfigSetting')
                ->with('http-basic.' . $this->origin);
        }
    }

    public function testGetTokenWithoutAccessToken()
    {
        $this->assertSame('', $this->bitbucket->getToken());
    }

    /**
     * @depends testRequestAccessTokenWithValidOAuthConsumerAndValidStoredAccessToken
     *
     * @param Bitbucket $bitbucket
     */
    public function testGetTokenWithAccessToken(Bitbucket $bitbucket)
    {
        $this->assertSame($this->token, $bitbucket->getToken());
    }

    public function testAuthorizeOAuthWithWrongOriginUrl()
    {
        $this->assertFalse($this->bitbucket->authorizeOAuth('non-' . $this->origin));
    }

    public function testAuthorizeOAuthWithoutAvailableGitConfigToken()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $process->expects($this->once())
            ->method('execute')
            ->willReturn(-1);

        $bitbucket = new Bitbucket($this->io, $this->config, $process, $this->httpDownloader, $this->time);

        $this->assertFalse($bitbucket->authorizeOAuth($this->origin));
    }

    public function testAuthorizeOAuthWithAvailableGitConfigToken()
    {
        $process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $process->expects($this->once())
            ->method('execute')
            ->willReturn(0);

        $bitbucket = new Bitbucket($this->io, $this->config, $process, $this->httpDownloader, $this->time);

        $this->assertTrue($bitbucket->authorizeOAuth($this->origin));
    }
}
