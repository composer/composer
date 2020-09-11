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

use Composer\Downloader\TransportException;
use Composer\Util\GitLab;
use Composer\Util\Http\Response;
use Composer\Test\TestCase;

/**
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabTest extends TestCase
{
    private $username = 'username';
    private $password = 'password';
    private $message = 'mymessage';
    private $origin = 'gitlab.com';
    private $token = 'gitlabtoken';

    public function testUsernamePasswordAuthenticationFlow()
    {
        $io = $this->getIOMock();
        $io
            ->expects($this->at(0))
            ->method('writeError')
            ->with($this->message)
        ;
        $io
            ->expects($this->once())
            ->method('ask')
            ->with('Username: ')
            ->willReturn($this->username)
        ;
        $io
            ->expects($this->once())
            ->method('askAndHideAnswer')
            ->with('Password: ')
            ->willReturn($this->password)
        ;

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url = sprintf('http://%s/oauth/token', $this->origin)),
                $this->anything()
            )
            ->willReturn(new Response(array('url' => $url), 200, array(), sprintf('{"access_token": "%s", "token_type": "bearer", "expires_in": 7200}', $this->token)))
        ;

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(2))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $gitLab = new GitLab($io, $config, null, $httpDownloader);

        $this->assertTrue($gitLab->authorizeOAuthInteractively('http', $this->origin, $this->message));
    }

    public function testUsernamePasswordFailure()
    {
        $this->setExpectedException('RuntimeException', 'Invalid GitLab credentials 5 times in a row, aborting.');
        $io = $this->getIOMock();
        $io
            ->expects($this->exactly(5))
            ->method('ask')
            ->with('Username: ')
            ->willReturn($this->username)
        ;
        $io
            ->expects($this->exactly(5))
            ->method('askAndHideAnswer')
            ->with('Password: ')
            ->willReturn($this->password)
        ;

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader
            ->expects($this->exactly(5))
            ->method('get')
            ->will($this->throwException(new TransportException('', 401)))
        ;

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(1))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $gitLab = new GitLab($io, $config, null, $httpDownloader);

        $gitLab->authorizeOAuthInteractively('https', $this->origin);
    }

    private function getIOMock()
    {
        $io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        return $io;
    }

    private function getConfigMock()
    {
        return $this->getMockBuilder('Composer\Config')->getMock();
    }

    private function getHttpDownloaderMock()
    {
        $httpDownloader = $this
            ->getMockBuilder('Composer\Util\HttpDownloader')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        return $httpDownloader;
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
}
