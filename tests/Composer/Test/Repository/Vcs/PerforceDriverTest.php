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

namespace Composer\Test\Repository\Vcs;

use Composer\Repository\Vcs\PerforceDriver;
use Composer\Util\Filesystem;
use Composer\Config;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDriverTest extends \PHPUnit_Framework_TestCase
{
    private $config;
    private $io;
    private $process;
    private $remoteFileSystem;
    private $testPath;

    public function setUp()
    {
        $this->testPath = sys_get_temp_dir() . '/composer-test';
        $this->config = new Config();
        $this->config->merge(
            array(
                 'config' => array(
                     'home' => $this->testPath,
                 ),
            )
        );

        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->process = $this->getMock('Composer\Util\ProcessExecutor');
        $this->remoteFileSystem = $this->getMockBuilder('Composer\Util\RemoteFilesystem')->disableOriginalConstructor()
                                  ->getMock();
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->testPath);
    }

    public function testInitializeCapturesVariablesFromRepoConfig()
    {
        $this->setUp();
        $repoConfig = array(
            'url'    => 'TEST_PERFORCE_URL',
            'depot'  => 'TEST_DEPOT_CONFIG',
            'branch' => 'TEST_BRANCH_CONFIG'
        );
        $driver = new PerforceDriver($repoConfig, $this->io, $this->config, $this->process, $this->remoteFileSystem);
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $arguments = array(
            array('depot' => 'TEST_DEPOT', 'branch' => 'TEST_BRANCH'),
            'port' => 'TEST_PORT',
            'path' => $this->testPath,
            $process,
            true,
            'TEST'
        );
        $perforce = $this->getMock('Composer\Util\Perforce', null, $arguments);
        $driver->setPerforce($perforce);
        $driver->initialize();
        $this->assertEquals('TEST_PERFORCE_URL', $driver->getUrl());
        $this->assertEquals('TEST_DEPOT_CONFIG', $driver->getDepot());
        $this->assertEquals('TEST_BRANCH_CONFIG', $driver->getBranch());
    }

    public function testInitializeLogsInAndConnectsClient()
    {
        $this->setUp();
        $repoConfig = array(
            'url'    => 'TEST_PERFORCE_URL',
            'depot'  => 'TEST_DEPOT_CONFIG',
            'branch' => 'TEST_BRANCH_CONFIG'
        );
        $driver = new PerforceDriver($repoConfig, $this->io, $this->config, $this->process, $this->remoteFileSystem);
        $perforce = $this->getMockBuilder('Composer\Util\Perforce')->disableOriginalConstructor()->getMock();
        $perforce->expects($this->at(0))
            ->method('p4Login')
            ->with($this->io);
        $perforce->expects($this->at(1))
            ->method('checkStream')
            ->with($this->equalTo('TEST_DEPOT_CONFIG'));
        $perforce->expects($this->at(2))
            ->method('writeP4ClientSpec');
        $perforce->expects($this->at(3))
            ->method('connectClient');

        $driver->setPerforce($perforce);
        $driver->initialize();
    }

    public function testHasComposerFile()
    {
        $repoConfig = array(
            'url'    => 'TEST_PERFORCE_URL',
            'depot'  => 'TEST_DEPOT_CONFIG',
            'branch' => 'TEST_BRANCH_CONFIG'
        );
        $driver = new PerforceDriver($repoConfig, $this->io, $this->config, $this->process, $this->remoteFileSystem);
        $process = $this->getMock('Composer\Util\ProcessExecutor');
        $arguments = array(
            array('depot' => 'TEST_DEPOT', 'branch' => 'TEST_BRANCH'),
            'port' => 'TEST_PORT',
            'path' => $this->testPath,
            $process,
            true,
            'TEST'
        );
        $perforce = $this->getMock('Composer\Util\Perforce', array('getComposerInformation'), $arguments);
        $perforce->expects($this->at(0))
            ->method('getComposerInformation')
            ->with($this->equalTo('//TEST_DEPOT_CONFIG/TEST_IDENTIFIER'))
            ->will($this->returnValue('Some json stuff'));
        $driver->setPerforce($perforce);
        $driver->initialize();
        $identifier = 'TEST_IDENTIFIER';
        $result = $driver->hasComposerFile($identifier);
        $this->assertTrue($result);
    }

    /**
     * Test that supports() simply return false.
     *
     * @covers \Composer\Repository\Vcs\PerforceDriver::supports
     *
     * @return void
     */
    public function testSupportsReturnsFalseNoDeepCheck()
    {
        $this->expectOutputString('');
        $this->assertFalse(PerforceDriver::supports($this->io, $this->config, 'existing.url'));
    }
}
