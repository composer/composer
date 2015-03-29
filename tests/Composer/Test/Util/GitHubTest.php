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
use Composer\Util\GitHub;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

/**
* @author Rob Bast <rob.bast@gmail.com>
*/
class GitHubTest extends \PHPUnit_Framework_TestCase
{
    private $username = 'username';
    private $password = 'password';
    private $authcode = 'authcode';
    private $message = 'mymessage';
    private $origin = 'github.com';
    private $token = 'githubtoken';

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
                $this->equalTo(sprintf('https://api.%s/authorizations', $this->origin)),
                $this->isFalse(),
                $this->anything()
            )
            ->willReturn(sprintf('{"token": "%s"}', $this->token))
        ;

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(2))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;
        $config
            ->expects($this->once())
            ->method('getConfigSource')
            ->willReturn($this->getConfJsonMock())
        ;

        $github = new GitHub($io, $config, null, $rfs);

        $this->assertTrue($github->authorizeOAuthInteractively($this->origin, $this->message));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Invalid GitHub credentials 5 times in a row, aborting.
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

        $github = new GitHub($io, $config, null, $rfs);

        $github->authorizeOAuthInteractively($this->origin);
    }

    public function testTwoFactorAuthentication()
    {
        $io = $this->getIOMock();
        $io
            ->expects($this->exactly(2))
            ->method('hasAuthentication')
            ->will($this->onConsecutiveCalls(true, true))
        ;
        $io
            ->expects($this->exactly(2))
            ->method('ask')
            ->withConsecutive(
                array('Username: '),
                array('Authentication Code: ')
            )
            ->will($this->onConsecutiveCalls($this->username, $this->authcode))
        ;
        $io
            ->expects($this->once())
            ->method('askAndHideAnswer')
            ->with('Password: ')
            ->willReturn($this->password)
        ;

        $exception = new TransportException('', 401);
        $exception->setHeaders(array('X-GitHub-OTP: required; app'));

        $rfs = $this->getRemoteFilesystemMock();
        $rfs
            ->expects($this->at(0))
            ->method('getContents')
            ->will($this->throwException($exception))
        ;
        $rfs
            ->expects($this->at(1))
            ->method('getContents')
            ->with(
                $this->equalTo($this->origin),
                $this->equalTo(sprintf('https://api.%s/authorizations', $this->origin)),
                $this->isFalse(),
                $this->callback(function ($array) {
                    $headers = GitHubTest::recursiveFind($array, 'header');
                    foreach ($headers as $string) {
                        if ('X-GitHub-OTP: authcode' === $string) {
                            return true;
                        }
                    }

                    return false;
                })
            )
            ->willReturn(sprintf('{"token": "%s"}', $this->token))
        ;

        $config = $this->getConfigMock();
        $config
            ->expects($this->atLeastOnce())
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;
        $config
            ->expects($this->atLeastOnce())
            ->method('getConfigSource')
            ->willReturn($this->getConfJsonMock())
        ;

        $github = new GitHub($io, $config, null, $rfs);

        $this->assertTrue($github->authorizeOAuthInteractively($this->origin));
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
            ->with('github-oauth.'.$this->origin)
        ;

        return $confjson;
    }

    public static function recursiveFind($array, $needle)
    {
        $iterator = new RecursiveArrayIterator($array);
        $recursive = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($recursive as $key => $value) {
            if ($key === $needle) {
                return $value;
            }
        }
    }
}
