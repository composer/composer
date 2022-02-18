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

use Composer\Util\GitHub;
use Composer\Test\TestCase;

/**
 * @author Rob Bast <rob.bast@gmail.com>
 */
class GitHubTest extends TestCase
{
    /** @var string */
    private $password = 'password';
    /** @var string */
    private $message = 'mymessage';
    /** @var string */
    private $origin = 'github.com';

    public function testUsernamePasswordAuthenticationFlow()
    {
        $io = $this->getIOMock();
        $io
            ->expects($this->atLeastOnce())
            ->method('writeError')
            ->withConsecutive([$this->message])
        ;
        $io
            ->expects($this->once())
            ->method('askAndHideAnswer')
            ->with('Token (hidden): ')
            ->willReturn($this->password)
        ;

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [['url' => sprintf('https://api.%s/', $this->origin), 'body' => '{}']],
            true
        );

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

        $github = new GitHub($io, $config, null, $httpDownloader);

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

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [['url' => sprintf('https://api.%s/', $this->origin), 'status' => 401]],
            true
        );

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(1))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $github = new GitHub($io, $config, null, $httpDownloader);

        $this->assertFalse($github->authorizeOAuthInteractively($this->origin));
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\IO\ConsoleIO
     */
    private function getIOMock()
    {
        $io = $this
            ->getMockBuilder('Composer\IO\ConsoleIO')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        return $io;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Config
     */
    private function getConfigMock()
    {
        return $this->getMockBuilder('Composer\Config')->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Config\JsonConfigSource
     */
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

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Config\JsonConfigSource
     */
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
}
