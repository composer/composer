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
use Composer\Util\Http\Response;
use Composer\Test\TestCase;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

/**
 * @author Rob Bast <rob.bast@gmail.com>
 */
class GitHubTest extends TestCase
{
    private $password = 'password';
    private $message = 'mymessage';
    private $origin = 'github.com';

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

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader
            ->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url = sprintf('https://api.%s/', $this->origin)),
                $this->anything()
            )
            ->willReturn(new Response(array('url' => $url), 200, array(), '{}'))
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
        $httpDownloader
            ->expects($this->exactly(1))
            ->method('get')
            ->will($this->throwException(new TransportException('', 401)))
        ;

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(1))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $github = new GitHub($io, $config, null, $httpDownloader);

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
