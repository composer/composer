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

use Composer\Util\GitLab;
use Composer\Test\TestCase;

/**
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabTest extends TestCase
{
    /** @var string */
    private $username = 'username';
    /** @var string */
    private $password = 'password';
    /** @var string */
    private $message = 'mymessage';
    /** @var string */
    private $origin = 'gitlab.com';
    /** @var string */
    private $token = 'gitlabtoken';
    /** @var string */
    private $refreshtoken = 'gitlabrefreshtoken';

    public function testUsernamePasswordAuthenticationFlow(): void
    {
        $io = $this->getIOMock();
        $io
            ->expects($this->atLeastOnce())
            ->method('writeError')
            ->withConsecutive([$this->message])
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
        $httpDownloader->expects(
            [['url' => sprintf('http://%s/oauth/token', $this->origin), 'body' => sprintf('{"access_token": "%s", "refresh_token": "%s", "token_type": "bearer", "expires_in": 7200, "created_at": 0}', $this->token, $this->refreshtoken)]],
            true
        );

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(2))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $gitLab = new GitLab($io, $config, null, $httpDownloader);

        $this->assertTrue($gitLab->authorizeOAuthInteractively('http', $this->origin, $this->message));
    }

    public function testUsernamePasswordFailure(): void
    {
        self::expectException('RuntimeException');
        self::expectExceptionMessage('Invalid GitLab credentials 5 times in a row, aborting.');
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
        $httpDownloader->expects(
            [
                ['url' => 'https://gitlab.com/oauth/token', 'status' => 401, 'body' => '{}'],
                ['url' => 'https://gitlab.com/oauth/token', 'status' => 401, 'body' => '{}'],
                ['url' => 'https://gitlab.com/oauth/token', 'status' => 401, 'body' => '{}'],
                ['url' => 'https://gitlab.com/oauth/token', 'status' => 401, 'body' => '{}'],
                ['url' => 'https://gitlab.com/oauth/token', 'status' => 401, 'body' => '{}'],
            ],
            true
        );

        $config = $this->getConfigMock();
        $config
            ->expects($this->exactly(1))
            ->method('getAuthConfigSource')
            ->willReturn($this->getAuthJsonMock())
        ;

        $gitLab = new GitLab($io, $config, null, $httpDownloader);

        $gitLab->authorizeOAuthInteractively('https', $this->origin);
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
}
