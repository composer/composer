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

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\Git;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Test\TestCase;

class GitTest extends TestCase
{
    /** @var Git */
    private $git;
    /** @var IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;
    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var ProcessExecutorMock */
    private $process;
    /** @var Filesystem&\PHPUnit\Framework\MockObject\MockObject */
    private $fs;

    protected function setUp(): void
    {
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->config = $this->getMockBuilder('Composer\Config')->disableOriginalConstructor()->getMock();
        $this->process = $this->getProcessExecutorMock();
        $this->fs = $this->getMockBuilder('Composer\Util\Filesystem')->disableOriginalConstructor()->getMock();
        $this->git = new Git($this->io, $this->config, $this->process, $this->fs);
    }

    /**
     * @dataProvider publicGithubNoCredentialsProvider
     *
     * @param string $protocol
     * @param string $expectedUrl
     */
    public function testRunCommandPublicGitHubRepositoryNotInitialClone($protocol, $expectedUrl)
    {
        $commandCallable = function ($url) use ($expectedUrl) {
            $this->assertSame($expectedUrl, $url);

            return 'git command';
        };

        $this->mockConfig($protocol);

        $this->process->expects(array('git command'), true);

        $this->git->runCommand($commandCallable, 'https://github.com/acme/repo', null, true);
    }

    public function publicGithubNoCredentialsProvider()
    {
        return array(
            array('ssh', 'git@github.com:acme/repo'),
            array('https', 'https://github.com/acme/repo'),
        );
    }

    public function testRunCommandPrivateGitHubRepositoryNotInitialCloneNotInteractiveWithoutAuthentication()
    {
        self::expectException('RuntimeException');

        $commandCallable = function ($url) {
            $this->assertSame('https://github.com/acme/repo', $url);

            return 'git command';
        };

        $this->mockConfig('https');

        $this->process->expects(array(
            array('cmd' => 'git command', 'return' => 1),
            array('cmd' => 'git --version', 'return' => 0),
        ), true);

        $this->git->runCommand($commandCallable, 'https://github.com/acme/repo', null, true);
    }

    /**
     * @dataProvider privateGithubWithCredentialsProvider
     *
     * @param string $gitUrl
     * @param string $protocol
     * @param string $gitHubToken
     * @param string $expectedUrl
     * @param int    $expectedFailuresBeforeSuccess
     */
    public function testRunCommandPrivateGitHubRepositoryNotInitialCloneNotInteractiveWithAuthentication($gitUrl, $protocol, $gitHubToken, $expectedUrl, $expectedFailuresBeforeSuccess)
    {
        $commandCallable = function ($url) use ($expectedUrl) {
            if ($url !== $expectedUrl) {
                return 'git command failing';
            }

            return 'git command ok';
        };

        $this->mockConfig($protocol);

        $expectedCalls = array_fill(0, $expectedFailuresBeforeSuccess, array('cmd' => 'git command failing', 'return' => 1));
        $expectedCalls[] = array('cmd' => 'git command ok', 'return' => 0);

        $this->process->expects($expectedCalls, true);

        $this->io
            ->method('isInteractive')
            ->willReturn(false);

        $this->io
            ->expects($this->atLeastOnce())
            ->method('hasAuthentication')
            ->with($this->equalTo('github.com'))
            ->willReturn(true);

        $this->io
            ->expects($this->atLeastOnce())
            ->method('getAuthentication')
            ->with($this->equalTo('github.com'))
            ->willReturn(array('username' => 'token', 'password' => $gitHubToken));

        $this->git->runCommand($commandCallable, $gitUrl, null, true);
    }

    public function privateGithubWithCredentialsProvider()
    {
        return array(
            array('git@github.com:acme/repo.git', 'ssh', 'MY_GITHUB_TOKEN', 'https://token:MY_GITHUB_TOKEN@github.com/acme/repo.git', 1),
            array('https://github.com/acme/repo', 'https', 'MY_GITHUB_TOKEN', 'https://token:MY_GITHUB_TOKEN@github.com/acme/repo.git', 2),
        );
    }

    /**
     * @param string $protocol
     *
     * @return void
     */
    private function mockConfig($protocol)
    {
        $this->config
            ->method('get')
            ->willReturnMap(array(
                array('github-domains', 0, array('github.com')),
                array('github-protocols', 0, array($protocol)),
            ));
    }
}
