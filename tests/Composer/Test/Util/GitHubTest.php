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
            ->method('askAndHideAnswer')
            ->with('Token (hidden): ')
            ->willReturn($this->password)
        ;

        $rfs = $this->getRemoteFilesystemMock();
        $rfs
            ->expects($this->once())
            ->method('getContents')
            ->with(
                $this->equalTo($this->origin),
                $this->equalTo(sprintf('https://api.%s/', $this->origin)),
                $this->isFalse(),
                $this->anything()
            )
            ->willReturn(sprintf('{}', $this->token))
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

    public function testUsernamePasswordFailure()
    {
        $io = $this->getIOMock();
        $io
            ->expects($this->exactly(1))
            ->method('askAndHideAnswer')
            ->with('Token (hidden): ')
            ->willReturn($this->password)
        ;

        $rfs = $this->getRemoteFilesystemMock();
        $rfs
            ->expects($this->exactly(1))
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

        $this->assertFalse($github->authorizeOAuthInteractively($this->origin));
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
