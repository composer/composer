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
use Composer\Util\Perforce;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDriverTest extends \PHPUnit_Framework_TestCase
{
    protected $config;
    protected $io;
    protected $process;
    protected $remoteFileSystem;
    protected $testPath;
    protected $driver;
    protected $repoConfig;

    const TEST_URL    = 'TEST_PERFORCE_URL';
    const TEST_DEPOT  = 'TEST_DEPOT_CONFIG';
    const TEST_BRANCH = 'TEST_BRANCH_CONFIG';

    protected function setUp()
    {
        $this->testPath         = sys_get_temp_dir() . '/composer-test';
        $this->config           = $this->getTestConfig($this->testPath);
        $this->repoConfig       = $this->getTestRepoConfig();
        $this->io               = $this->getMockIOInterface();
        $this->process          = $this->getMockProcessExecutor();
        $this->remoteFileSystem = $this->getMockRemoteFilesystem();
        $this->perforce         = $this->getMockPerforce();
        $this->driver = new PerforceDriver($this->repoConfig, $this->io, $this->config, $this->process, $this->remoteFileSystem);
        $this->overrideDriverInternalPerforce($this->perforce);
    }

    protected function tearDown()
    {
        //cleanup directory under test path
        $fs = new Filesystem;
        $fs->removeDirectory($this->testPath);
        $this->driver           = null;
        $this->perforce         = null;
        $this->remoteFileSystem = null;
        $this->process          = null;
        $this->io               = null;
        $this->repoConfig       = null;
        $this->config           = null;
        $this->testPath         = null;
    }

    protected function overrideDriverInternalPerforce(Perforce $perforce)
    {
        $reflectionClass = new \ReflectionClass($this->driver);
        $property = $reflectionClass->getProperty('perforce');
        $property->setAccessible(true);
        $property->setValue($this->driver, $perforce);
    }

    protected function getTestConfig($testPath)
    {
        $config = new Config();
        $config->merge(array('config' => array('home' => $testPath)));

        return $config;
    }

    protected function getTestRepoConfig()
    {
        return array(
            'url'    => self::TEST_URL,
            'depot'  => self::TEST_DEPOT,
            'branch' => self::TEST_BRANCH,
        );
    }

    protected function getMockIOInterface()
    {
        return $this->getMock('Composer\IO\IOInterface');
    }

    protected function getMockProcessExecutor()
    {
        return $this->getMock('Composer\Util\ProcessExecutor');
    }

    protected function getMockRemoteFilesystem()
    {
        return $this->getMockBuilder('Composer\Util\RemoteFilesystem')->disableOriginalConstructor()->getMock();
    }

    protected function getMockPerforce()
    {
        $methods = array('p4login', 'checkStream', 'writeP4ClientSpec', 'connectClient', 'getComposerInformation', 'cleanupClientSpec');

        return $this->getMockBuilder('Composer\Util\Perforce', $methods)->disableOriginalConstructor()->getMock();
    }

    public function testInitializeCapturesVariablesFromRepoConfig()
    {
        $driver = new PerforceDriver($this->repoConfig, $this->io, $this->config, $this->process, $this->remoteFileSystem);
        $driver->initialize();
        $this->assertEquals(self::TEST_URL, $driver->getUrl());
        $this->assertEquals(self::TEST_DEPOT, $driver->getDepot());
        $this->assertEquals(self::TEST_BRANCH, $driver->getBranch());
    }

    public function testInitializeLogsInAndConnectsClient()
    {
        $this->perforce->expects($this->at(0))->method('p4Login')->with($this->identicalTo($this->io));
        $this->perforce->expects($this->at(1))->method('checkStream')->with($this->equalTo(self::TEST_DEPOT));
        $this->perforce->expects($this->at(2))->method('writeP4ClientSpec');
        $this->perforce->expects($this->at(3))->method('connectClient');
        $this->driver->initialize();
    }

    /**
     * @depends testInitializeCapturesVariablesFromRepoConfig
     * @depends testInitializeLogsInAndConnectsClient
     */
    public function testHasComposerFileReturnsFalseOnNoComposerFile()
    {
        $identifier = 'TEST_IDENTIFIER';
        $formatted_depot_path = '//' . self::TEST_DEPOT . '/' . $identifier;
        $this->perforce->expects($this->any())->method('getComposerInformation')->with($this->equalTo($formatted_depot_path))->will($this->returnValue(array()));
        $this->driver->initialize();
        $result = $this->driver->hasComposerFile($identifier);
        $this->assertFalse($result);
    }

    /**
     * @depends testInitializeCapturesVariablesFromRepoConfig
     * @depends testInitializeLogsInAndConnectsClient
     */
    public function testHasComposerFileReturnsTrueWithOneOrMoreComposerFiles()
    {
        $identifier = 'TEST_IDENTIFIER';
        $formatted_depot_path = '//' . self::TEST_DEPOT . '/' . $identifier;
        $this->perforce->expects($this->any())->method('getComposerInformation')->with($this->equalTo($formatted_depot_path))->will($this->returnValue(array('')));
        $this->driver->initialize();
        $result = $this->driver->hasComposerFile($identifier);
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

    public function testCleanup()
    {
        $this->perforce->expects($this->once())->method('cleanupClientSpec');
        $this->driver->cleanup();
    }
}
