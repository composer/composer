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

/**
 * @author Paul Wenke <wenke.paul@gmail.com>
 */
class BitbucketTest extends \PHPUnit_Framework_TestCase
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
    /** @type \Composer\Util\RemoteFilesystem|\PHPUnit_Framework_MockObject_MockObject */
    private $rfs;
    /** @type \Composer\Config|\PHPUnit_Framework_MockObject_MockObject */
    private $config;
    /** @type Bitbucket */
    private $bitbucket;

    protected function setUp()
    {
        $this->io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->rfs = $this
            ->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $this->config = $this->getMock('Composer\Config');

        $this->bitbucket = new Bitbucket($this->io, $this->config, null, $this->rfs);
    }

    public function testRequestAccessTokenWithValidOAuthConsumer()
    {
        $this->io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->origin, $this->consumer_key, $this->consumer_secret);

        $this->rfs->expects($this->once())
            ->method('getContents')
            ->with(
                $this->origin,
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                false,
                array(
                    'retry-auth-failure' => false,
                    'http' => array(
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    )
                )
            )
            ->willReturn(
                sprintf(
                    '{"access_token": "%s", "scopes": "repository", "expires_in": 3600, "refresh_token": "refreshtoken", "token_type": "bearer"}',
                    $this->token
                )
            );

        $this->assertEquals(
            array(
                'access_token' => $this->token,
                'scopes' => 'repository',
                'expires_in' => 3600,
                'refresh_token' => 'refreshtoken',
                'token_type' => 'bearer'
            ),
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

        $this->rfs->expects($this->once())
            ->method('getContents')
            ->with(
                $this->origin,
                Bitbucket::OAUTH2_ACCESS_TOKEN_URL,
                false,
                array(
                    'retry-auth-failure' => false,
                    'http' => array(
                        'method' => 'POST',
                        'content' => 'grant_type=client_credentials',
                    )
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

        $this->assertEquals(array(), $this->bitbucket->requestToken($this->origin, $this->username, $this->password));
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

        $this->rfs
            ->expects($this->once())
            ->method('getContents')
            ->with(
                $this->equalTo($this->origin),
                $this->equalTo(sprintf('https://%s/site/oauth2/access_token', $this->origin)),
                $this->isFalse(),
                $this->anything()
            )
            ->willReturn(sprintf('{}', $this->token))
        ;

        $authJson = $this->getAuthJsonMock();
        $this->config
            ->expects($this->exactly(3))
            ->method('getAuthConfigSource')
            ->willReturn($authJson)
        ;
        $this->config
            ->expects($this->once())
            ->method('getConfigSource')
            ->willReturn($this->getConfJsonMock())
        ;

        $authJson->expects($this->once())
            ->method('addConfigSetting')
            ->with(
                'bitbucket-oauth.'.$this->origin,
                array(
                    'consumer-key' => $this->consumer_key,
                    'consumer-secret' => $this->consumer_secret
                )
            );

        $authJson->expects($this->once())
            ->method('removeConfigSetting')
            ->with('http-basic.'.$this->origin);

        $this->assertTrue($this->bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
    }

    private function getAuthJsonMock()
    {
        $authjson = $this
            ->getMockBuilder('Composer\Config\JsonConfigSource')
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $authjson
            ->expects($this->atLeastOnce())
            ->method('getName')
            ->willReturn('auth.json')
        ;

        return $authjson;
    }

    private function getConfJsonMock()
    {
        $confjson = $this
            ->getMockBuilder('Composer\Config\JsonConfigSource')
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $confjson
            ->expects($this->atLeastOnce())
            ->method('removeConfigSetting')
            ->with('bitbucket-oauth.'.$this->origin)
        ;

        return $confjson;
    }
}
