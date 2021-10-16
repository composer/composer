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
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Config;
use Composer\Util\Perforce;
use Composer\Test\Mock\ProcessExecutorMock;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDriverTest extends TestCase
{
    /**
     * @var Config
     */
    protected $config;
    /**
     * @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $io;
    /**
     * @var ProcessExecutorMock
     */
    protected $process;
    /**
     * @var \Composer\Util\HttpDownloader&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $httpDownloader;
    /**
     * @var string
     */
    protected $testPath;
    /**
     * @var PerforceDriver
     */
    protected $driver;
    /**
     * @var array<string, string>
     */
    protected $repoConfig;
    /**
     * @var Perforce&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $perforce;

    const TEST_URL = 'TEST_PERFORCE_URL';
    const TEST_DEPOT = 'TEST_DEPOT_CONFIG';
    const TEST_BRANCH = 'TEST_BRANCH_CONFIG';

    protected function setUp()
    {
        $this->testPath = $this->getUniqueTmpDirectory();
        $this->config = $this->getTestConfig($this->testPath);
        $this->repoConfig = array(
            'url' => self::TEST_URL,
            'depot' => self::TEST_DEPOT,
            'branch' => self::TEST_BRANCH,
        );
        $this->io = $this->getMockIOInterface();
        $this->process = new ProcessExecutorMock;
        $this->httpDownloader = $this->getMockHttpDownloader();
        $this->perforce = $this->getMockPerforce();
        $this->driver = new PerforceDriver($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->process);
        $this->overrideDriverInternalPerforce($this->perforce);
    }

    protected function tearDown()
    {
        //cleanup directory under test path
        $fs = new Filesystem;
        $fs->removeDirectory($this->testPath);
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

    protected function getMockIOInterface()
    {
        return $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    protected function getMockHttpDownloader()
    {
        return $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
    }

    protected function getMockPerforce()
    {
        $methods = array('p4login', 'checkStream', 'writeP4ClientSpec', 'connectClient', 'getComposerInformation', 'cleanupClientSpec');

        return $this->getMockBuilder('Composer\Util\Perforce')->disableOriginalConstructor()->getMock();
    }

    public function testInitializeCapturesVariablesFromRepoConfig()
    {
        $driver = new PerforceDriver($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();
        $this->assertEquals(self::TEST_URL, $driver->getUrl());
        $this->assertEquals(self::TEST_DEPOT, $driver->getDepot());
        $this->assertEquals(self::TEST_BRANCH, $driver->getBranch());
    }

    public function testInitializeLogsInAndConnectsClient()
    {
        $this->perforce->expects($this->at(0))->method('p4Login');
        $this->perforce->expects($this->at(1))->method('checkStream');
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
