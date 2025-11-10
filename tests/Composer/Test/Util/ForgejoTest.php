<?php declare(strict_types=1);

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

use Composer\Test\TestCase;
use Composer\Util\Forgejo;

class ForgejoTest extends TestCase
{
    /** @var string */
    private $username = 'username';
    /** @var string */
    private $accessToken = 'access-token';
    /** @var string */
    private $message = 'mymessage';
    /** @var string */
    private $origin = 'codeberg.org';

    public function testUsernamePasswordAuthenticationFlow(): void
    {
        $io = $this->getIOMock();
        $io->expects([
            ['text' => $this->message],
            ['ask' => 'Username: ', 'reply' => $this->username],
            ['ask' => 'Token (hidden): ', 'reply' => $this->accessToken],
        ]);

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [['url' => sprintf('https://%s/api/v1/version', $this->origin), 'body' => '{}']],
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

        $forgejo = new Forgejo($io, $config, $httpDownloader);

        self::assertTrue($forgejo->authorizeOAuthInteractively($this->origin, $this->message));
    }

    public function testUsernamePasswordFailure(): void
    {
        $io = $this->getIOMock();
        $io->expects([
            ['ask' => 'Username: ', 'reply' => $this->username],
            ['ask' => 'Token (hidden): ', 'reply' => $this->accessToken],
        ]);

        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [['url' => sprintf('https://%s/api/v1/version', $this->origin), 'status' => 404]],
            true
        );

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(1))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $forgejo = new Forgejo($io, $config, $httpDownloader);

        self::assertFalse($forgejo->authorizeOAuthInteractively($this->origin));
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
            ->with('forgejo-token.'.$this->origin)
        ;

        return $confjson;
    }
}
