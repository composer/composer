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
use Composer\TestCase;
use Composer\Util\Git;

/**
 * Git test case
 */
class GitTest extends TestCase
{
    /**
     * @param null $io
     * @param null $executor
     * @param null $config
     * @param null $filesystem
     * @return Git
     */
    protected function getGitMock($io = null, $executor = null, $config = null, $filesystem = null)
    {
        $io = $io ?: $this->getMock('Composer\IO\IOInterface');
        $executor = $executor ?: $this->getMock('Composer\Util\ProcessExecutor');
        $filesystem = $filesystem ?: $this->getMock('Composer\Util\Filesystem');
        if (!$config) {
            $config = new Config();
            $config->merge(array(
                'config' => array(
                    'store-auths' => false
                )
            ));
        }

        return new Git($io, $config, $executor, $filesystem);
    }

    /**
     * test user gets authenticated when auth is stored
     */
    public function testPrivateGitRepositoryWithAuthInConfig()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())->method('hasAuthentication')->willReturn(true);
        $io->expects($this->once())->method('getAuthentication')->willReturn(array(
            'username' => 'user',
            'password' => 'pass'
        ));
        $io->expects($this->once())->method('setAuthentication')->with('example.com', 'user', 'pass');

        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $process->expects($this->at(0))->method('execute')->with('git fetch https://example.com/composer')->willReturn(1);
        $process->expects($this->at(1))->method('getErrorOutput')->willReturn('fatal: Authentication failed');
        $process->expects($this->at(2))->method('execute')->with('git fetch https://user:pass@example.com/composer')->willReturn(0);

        $callback = function ($url) {
            return 'git fetch ' . $url;
        };

        $git = $this->getGitMock($io, $process);
        $git->runCommand($callback, 'https://example.com/composer', '/home', true);
    }

    /**
     * test exception when username and password are wrong
     */
    public function testPrivateGitRepositoryWrongAuthCredentials()
    {
        $this->setExpectedException('RuntimeException');

        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())->method('hasAuthentication')->willReturn(true);
        $io->expects($this->once())->method('getAuthentication')->willReturn(array(
            'username' => 'user',
            'password' => 'pass'
        ));
        $io->expects($this->never())->method('setAuthentication');

        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $process->expects($this->at(0))->method('execute')->with('git fetch https://example.com/composer')->willReturn(1);
        $process->expects($this->at(1))->method('getErrorOutput')->willReturn('fatal: Authentication failed');
        $process->expects($this->at(2))->method('execute')->with('git fetch https://user:pass@example.com/composer')->willReturn(1);

        $callback = function ($url) {
            return 'git fetch ' . $url;
        };

        $git = $this->getGitMock($io, $process);
        $git->runCommand($callback, 'https://example.com/composer', '/home', true);
    }

    /**
     * test user gets prompted when auth is not stored
     */
    public function testPrivateGitRepositoryWithAuthPrompt()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())->method('hasAuthentication')->willReturn(false);
        $io->expects($this->once())->method('isInteractive')->willReturn(true);
        $io->expects($this->once())->method('ask')->willReturn('user');
        $io->expects($this->once())->method('askAndHideAnswer')->willReturn('pass');
        $io->expects($this->once())->method('setAuthentication')->with('example.com', 'user', 'pass');

        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $process->expects($this->at(0))->method('execute')->with('git fetch https://example.com/composer')->willReturn(1);
        $process->expects($this->at(1))->method('getErrorOutput')->willReturn('fatal: Authentication failed');
        $process->expects($this->at(2))->method('execute')->with('git fetch https://user:pass@example.com/composer')->willReturn(0);

        $callback = function ($url) {
            return 'git fetch ' . $url;
        };

        $git = $this->getGitMock($io, $process);
        $git->runCommand($callback, 'https://example.com/composer', '/home', true);
    }

    /**
     * test default username value
     */
    public function testPrivateGitRepositoryWithAuthPromptDefaultUsername()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())->method('hasAuthentication')->willReturn(false);
        $io->expects($this->once())->method('isInteractive')->willReturn(true);
        $io->expects($this->once())->method('ask')->with($this->anything(), 'user')->willReturn('user');
        $io->expects($this->once())->method('askAndHideAnswer')->willReturn('pass');
        $io->expects($this->once())->method('setAuthentication')->with('example.com', 'user', 'pass');

        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $process->expects($this->at(0))->method('execute')->with('git fetch https://user@example.com/composer')->willReturn(1);
        $process->expects($this->at(1))->method('getErrorOutput')->willReturn('fatal: Authentication failed');
        $process->expects($this->at(2))->method('execute')->with('git fetch https://user:pass@example.com/composer')->willReturn(0);

        $callback = function ($url) {
            return 'git fetch ' . $url;
        };

        $git = $this->getGitMock($io, $process);
        $git->runCommand($callback, 'https://user@example.com/composer', '/home', true);
    }
}
