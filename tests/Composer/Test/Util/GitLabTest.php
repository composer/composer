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

/**
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabTest extends \PHPUnit_Framework_TestCase
{
    private $username = 'username';
    private $password = 'password';
    private $authcode = 'authcode';
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

        $rfs = $this->getRemoteFilesystemMock();
        $rfs
            ->expects($this->once())
            ->method('getContents')
            ->with(
                $this->equalTo($this->origin),
                $this->equalTo(sprintf('http://%s/oauth/token', $this->origin)),
                $this->isFalse(),
                $this->anything()
            )
            ->willReturn(sprintf('{"access_token": "%s", "token_type": "bearer", "expires_in": 7200}', $this->token))
        ;

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(2))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $gitLab = new GitLab($io, $config, null, $rfs);

        $this->assertTrue($gitLab->authorizeOAuthInteractively('http', $this->origin, $this->message));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Invalid GitLab credentials 5 times in a row, aborting.
     */
    public function testUsernamePasswordFailure()
    {
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

        $rfs = $this->getRemoteFilesystemMock();
        $rfs
            ->expects($this->exactly(5))
            ->method('getContents')
            ->will($this->throwException(new TransportException('', 401)))
        ;

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(1))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $gitLab = new GitLab($io, $config, null, $rfs);

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
        $config = $this->getMock('Composer\Config');

        return $config;
    }

    private function getRemoteFilesystemMock()
    {
        $rfs = $this
            ->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        return $rfs;
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
