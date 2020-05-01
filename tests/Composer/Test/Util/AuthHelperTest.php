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

use Composer\IO\IOInterface;
use Composer\Test\TestCase;
use Composer\Util\AuthHelper;

/**
 * @author Michael Chekin <mchekin@gmail.com>
 */
class AuthHelperTest extends TestCase
{
    /** @type \Composer\IO\IOInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $io;

    /** @type \Composer\Config|\PHPUnit_Framework_MockObject_MockObject */
    private $config;

    /** @type AuthHelper */
    private $authHelper;

    protected function setUp()
    {
        $this->io = $this
            ->getMockBuilder('Composer\IO\IOInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->config = $this->getMockBuilder('Composer\Config')->getMock();

        $this->authHelper = new AuthHelper($this->io, $this->config);
    }

    public function testAddAuthenticationHeaderWithoutAuthCredentials()
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'http://example.org';
        $url = 'file://' . __FILE__;

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(false);

        $this->assertSame(
            $headers,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }

    public function testAddAuthenticationHeaderWithBearerPassword()
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'http://example.org';
        $url = 'file://' . __FILE__;
        $credentials = array(
            'username' => 'my_username',
            'password' => 'bearer'
        );

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($credentials);

        $expectedHeaders = array_merge($headers, array('Authorization: Bearer ' . $credentials['username']));

        $this->assertSame(
            $expectedHeaders,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }

    public function testAddAuthenticationHeaderWithGithubToken()
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'github.com';
        $url = 'https://api.github.com/';
        $credentials = array(
            'username' => 'my_username',
            'password' => 'x-oauth-basic'
        );

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($credentials);

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using GitHub token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, array('Authorization: token ' . $credentials['username']));

        $this->assertSame(
            $expectedHeaders,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }

    public function testAddAuthenticationHeaderWithGitlabOathToken()
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'gitlab.com';
        $url = 'https://api.gitlab.com/';
        $credentials = array(
            'username' => 'my_username',
            'password' => 'oauth2'
        );

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($credentials);

        $this->config->expects($this->once())
            ->method('get')
            ->with('gitlab-domains')
            ->willReturn(array($origin));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using GitLab OAuth token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, array('Authorization: Bearer ' . $credentials['username']));

        $this->assertSame(
            $expectedHeaders,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }

    public function gitlabPrivateTokenProvider()
    {
        return array(
          array('private-token'),
          array('gitlab-ci-token'),
        );
    }

    /**
     * @dataProvider gitlabPrivateTokenProvider
     *
     * @param $password
     */
    public function testAddAuthenticationHeaderWithGitlabPrivateToken($password)
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'gitlab.com';
        $url = 'https://api.gitlab.com/';
        $credentials = array(
            'username' => 'my_username',
            'password' => $password
        );

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($credentials);

        $this->config->expects($this->once())
            ->method('get')
            ->with('gitlab-domains')
            ->willReturn(array($origin));

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using GitLab private token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, array('PRIVATE-TOKEN: ' . $credentials['username']));

        $this->assertSame(
            $expectedHeaders,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }

    public function testAddAuthenticationHeaderWithBitbucketOathToken()
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'bitbucket.org';
        $url = 'https://bitbucket.org/site/oauth2/authorize';
        $credentials = array(
            'username' => 'x-token-auth',
            'password' => 'my_password'
        );

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($credentials);

        $this->config->expects($this->once())
            ->method('get')
            ->with('gitlab-domains')
            ->willReturn(array());

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('Using Bitbucket OAuth token authentication', true, IOInterface::DEBUG);

        $expectedHeaders = array_merge($headers, array('Authorization: Bearer ' . $credentials['password']));

        $this->assertSame(
            $expectedHeaders,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }

    public function bitbucketPublicUrlProvider()
    {
        return array(
            array('https://bitbucket.org/user/repo/downloads/whatever'),
            array('https://bbuseruploads.s3.amazonaws.com/9421ee72-638e-43a9-82ea-39cfaae2bfaa/downloads/b87c59d9-54f3-4922-b711-d89059ec3bcf'),
        );
    }

    /**
     * @dataProvider bitbucketPublicUrlProvider
     *
     * @param $url
     */
    public function testAddAuthenticationHeaderWithBitbucketPublicUrl($url)
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'bitbucket.org';
        $credentials = array(
            'username' => 'x-token-auth',
            'password' => 'my_password'
        );

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(true);

        $this->io->expects($this->once())
            ->method('getAuthentication')
            ->with($origin)
            ->willReturn($credentials);

        $this->config->expects($this->once())
            ->method('get')
            ->with('gitlab-domains')
            ->willReturn(array());

        $this->assertSame(
            $headers,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }
}
