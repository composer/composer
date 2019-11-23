<?php

namespace Composer\Test\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\Git;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\TestCase;

class GitTest extends TestCase
{
    /** @var Git */
    private $git;
    /** @var IOInterface&\PHPUnit_Framework_MockObject_MockObject */
    private $io;
    /** @var Config&\PHPUnit_Framework_MockObject_MockObject */
    private $config;
    /** @var ProcessExecutor&\PHPUnit_Framework_MockObject_MockObject */
    private $process;
    /** @var Filesystem&\PHPUnit_Framework_MockObject_MockObject */
    private $fs;

    protected function setUp()
    {
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->config = $this->getMockBuilder('Composer\Config')->disableOriginalConstructor()->getMock();
        $this->process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->disableOriginalConstructor()->getMock();
        $this->fs = $this->getMockBuilder('Composer\Util\Filesystem')->disableOriginalConstructor()->getMock();
        $this->git = new Git($this->io, $this->config, $this->process, $this->fs);
    }

    /**
     * @dataProvider publicGithubNoCredentialsProvider
     */
    public function testRunCommandPublicGitHubRepositoryNotInitialClone($protocol, $expectedUrl)
    {
        $that = $this;
        $commandCallable = function ($url) use ($that, $expectedUrl) {
            $that->assertSame($expectedUrl, $url);

            return 'git command';
        };

        $this->mockConfig($protocol);

        $this->process
            ->expects($this->once())
            ->method('execute')
            ->with($this->equalTo('git command'))
            ->willReturn(0);

        $this->git->runCommand($commandCallable, 'https://github.com/acme/repo', null, true);
    }

    public function publicGithubNoCredentialsProvider()
    {
        return array(
            array('ssh', 'git@github.com:acme/repo'),
            array('https', 'https://github.com/acme/repo'),
        );
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testRunCommandPrivateGitHubRepositoryNotInitialCloneNotInteractiveWithoutAuthentication()
    {
        $that = $this;
        $commandCallable = function ($url) use ($that) {
            $that->assertSame('https://github.com/acme/repo', $url);

            return 'git command';
        };

        $this->mockConfig('https');

        $this->process
            ->method('execute')
            ->willReturnMap(array(
                array('git command', null, null, 1),
                array('git --version', null, null, 0),
            ));

        $this->git->runCommand($commandCallable, 'https://github.com/acme/repo', null, true);
    }

    /**
     * @dataProvider privateGithubWithCredentialsProvider
     */
    public function testRunCommandPrivateGitHubRepositoryNotInitialCloneNotInteractiveWithAuthentication($gitUrl, $protocol, $gitHubToken, $expectedUrl)
    {
        $commandCallable = function ($url) use ($expectedUrl) {
            if ($url !== $expectedUrl) {
                return 'git command failing';
            }

            return 'git command ok';
        };

        $this->mockConfig($protocol);

        $this->process
            ->method('execute')
            ->willReturnMap(array(
                array('git command failing', null, null, 1),
                array('git command ok', null, null, 0),
            ));

        $this->io
            ->method('isInteractive')
            ->willReturn(false);

        $this->io
            ->method('hasAuthentication')
            ->with($this->equalTo('github.com'))
            ->willReturn(true);

        $this->io
            ->method('getAuthentication')
            ->with($this->equalTo('github.com'))
            ->willReturn(array('username' => 'token', 'password' => $gitHubToken));

        $this->git->runCommand($commandCallable, $gitUrl, null, true);
    }

    public function privateGithubWithCredentialsProvider()
    {
        return array(
            array('git@github.com:acme/repo.git', 'ssh', 'MY_GITHUB_TOKEN', 'https://token:MY_GITHUB_TOKEN@github.com/acme/repo.git'),
            array('https://github.com/acme/repo', 'https', 'MY_GITHUB_TOKEN', 'https://token:MY_GITHUB_TOKEN@github.com/acme/repo.git'),
        );
    }

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
