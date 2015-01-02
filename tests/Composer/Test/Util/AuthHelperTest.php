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
use Composer\Config\ConfigSourceInterface;
use Composer\IO\IOInterface;
use Composer\TestCase;
use Composer\Util\AuthHelper;

/**
 * AuthHelper test case
 */
class AuthHelperTest extends TestCase
{

    /**
     * @param IOInterface $io
     * @param ConfigSourceInterface $configSource
     * @return AuthHelper
     */
    protected function getHelperMock(IOInterface $io, ConfigSourceInterface $configSource)
    {
        $config = new Config();
        $config->setAuthConfigSource($configSource);

        return new AuthHelper($io, $config);
    }

    /**
     * test when helper should store for sure
     */
    public function testStoreAuthTrue()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())->method('getAuthentication')->with()->willReturn('password');

        $authConfigSource = $this->getMock('Composer\Config\ConfigSourceInterface');
        $authConfigSource->expects($this->never())->method('getName');
        $authConfigSource->expects($this->once())->method('addConfigSetting')->with('http-basic.example.com', 'password');

        $authHelper = $this->getHelperMock($io, $authConfigSource);
        $authHelper->storeAuth('example.com', true);
    }

    /**
     * test when helper should prompt and the user answers yes
     */
    public function testStoreAuthPromptYes()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())->method('askAndValidate')->with(
            'Do you want to store credentials unencrypted for example.com in auth.json ? [Yn] ',
            $this->anything(),
            false,
            'y'
        )->willReturn('y');
        $io->expects($this->once())->method('getAuthentication')->with()->willReturn('password');

        $authConfigSource = $this->getMock('Composer\Config\ConfigSourceInterface');
        $authConfigSource->expects($this->once())->method('getName')->with()->willReturn('auth.json');
        $authConfigSource->expects($this->once())->method('addConfigSetting')->with('http-basic.example.com', 'password');

        $authHelper = $this->getHelperMock($io, $authConfigSource);
        $authHelper->storeAuth('example.com', 'prompt');
    }

    /**
     * test when helper should prompt and the user answers no
     */
    public function testStoreAuthPromptNo()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())->method('askAndValidate')->willReturn('n');
        $io->expects($this->never())->method('getAuthentication');

        $authConfigSource = $this->getMock('Composer\Config\ConfigSourceInterface');
        $authConfigSource->expects($this->once())->method('getName')->with()->willReturn('auth.json');
        $authConfigSource->expects($this->never())->method('addConfigSetting');

        $authHelper = $this->getHelperMock($io, $authConfigSource);
        $authHelper->storeAuth('example.com', 'prompt');
    }

    /**
     * test when helper should not store at all
     */
    public function testStoreAuthFalse()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->never())->method('getAuthentication')->with()->willReturn('password');

        $authConfigSource = $this->getMock('Composer\Config\ConfigSourceInterface');
        $authConfigSource->expects($this->never())->method('getName');
        $authConfigSource->expects($this->never())->method('addConfigSetting');

        $authHelper = $this->getHelperMock($io, $authConfigSource);
        $authHelper->storeAuth('example.com', false);
    }
}
