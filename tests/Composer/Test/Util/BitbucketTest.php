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
    private $authcode = 'authcode';
    private $message = 'mymessage';
    private $origin = 'bitbucket.org';
    private $token = 'bitbuckettoken';

    public function testUsernamePasswordAuthenticationFlow()
    {
        $io = $this->getIOMock();
        $io
            ->expects($this->at(0))
            ->method('writeError')
            ->with($this->message)
        ;

        $io->expects($this->exactly(2))
            ->method('askAndHideAnswer')
            ->withConsecutive(
                array('Consumer Key (hidden): '),
                array('Consumer Secret (hidden): ')
            )
            ->willReturnOnConsecutiveCalls($this->username, $this->password);

        $rfs = $this->getRemoteFilesystemMock();
        $rfs
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

        $bitbucket = new Bitbucket($io, $config, null, $rfs);

        $this->assertTrue($bitbucket->authorizeOAuthInteractively($this->origin, $this->message));
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
            ->with('bitbucket-oauth.'.$this->origin)
        ;

        return $confjson;
    }
}
