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

    private const TEST_URL = 'TEST_PERFORCE_URL';
    private const TEST_DEPOT = 'TEST_DEPOT_CONFIG';
    private const TEST_BRANCH = 'TEST_BRANCH_CONFIG';

    protected function setUp(): void
    {
        $this->testPath = self::getUniqueTmpDirectory();
        $this->config = $this->getTestConfig($this->testPath);
        $this->repoConfig = [
            'url' => self::TEST_URL,
            'depot' => self::TEST_DEPOT,
            'branch' => self::TEST_BRANCH,
        ];
        $this->io = $this->getMockIOInterface();
        $this->process = $this->getProcessExecutorMock();
        $this->httpDownloader = $this->getMockHttpDownloader();
        $this->perforce = $this->getMockPerforce();
        $this->driver = new PerforceDriver($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->process);
        $this->overrideDriverInternalPerforce($this->perforce);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        //cleanup directory under test path
        $fs = new Filesystem;
        $fs->removeDirectory($this->testPath);
    }

    protected function overrideDriverInternalPerforce(Perforce $perforce): void
    {
        $reflectionClass = new \ReflectionClass($this->driver);
        $property = $reflectionClass->getProperty('perforce');
        (\PHP_VERSION_ID < 80100) && $property->setAccessible(true);
        $property->setValue($this->driver, $perforce);
    }

    protected function getTestConfig(string $testPath): Config
    {
        $config = new Config();
        $config->merge(['config' => ['home' => $testPath]]);

        return $config;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\IO\IOInterface
     */
    protected function getMockIOInterface()
    {
        return $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Util\HttpDownloader
     */
    protected function getMockHttpDownloader()
    {
        return $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Util\Perforce
     */
    protected function getMockPerforce()
    {
        $methods = ['p4login', 'checkStream', 'writeP4ClientSpec', 'connectClient', 'getComposerInformation', 'cleanupClientSpec'];

        return $this->getMockBuilder('Composer\Util\Perforce')->disableOriginalConstructor()->getMock();
    }

    public function testInitializeCapturesVariablesFromRepoConfig(): void
    {
        $driver = new PerforceDriver($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();
        self::assertEquals(self::TEST_URL, $driver->getUrl());
        self::assertEquals(self::TEST_DEPOT, $driver->getDepot());
        self::assertEquals(self::TEST_BRANCH, $driver->getBranch());
    }

    public function testInitializeLogsInAndConnectsClient(): void
    {
        $this->perforce->expects($this->once())->method('p4Login');
        $this->perforce->expects($this->once())->method('checkStream');
        $this->perforce->expects($this->once())->method('writeP4ClientSpec');
        $this->perforce->expects($this->once())->method('connectClient');
        $this->driver->initialize();
    }

    /**
     * @depends testInitializeCapturesVariablesFromRepoConfig
     * @depends testInitializeLogsInAndConnectsClient
     */
    public function testHasComposerFileReturnsFalseOnNoComposerFile(): void
    {
        $identifier = 'TEST_IDENTIFIER';
        $formatted_depot_path = '//' . self::TEST_DEPOT . '/' . $identifier;
        $this->perforce->expects($this->any())->method('getComposerInformation')->with($this->equalTo($formatted_depot_path))->will($this->returnValue([]));
        $this->driver->initialize();
        $result = $this->driver->hasComposerFile($identifier);
        self::assertFalse($result);
    }

    /**
     * @depends testInitializeCapturesVariablesFromRepoConfig
     * @depends testInitializeLogsInAndConnectsClient
     */
    public function testHasComposerFileReturnsTrueWithOneOrMoreComposerFiles(): void
    {
        $identifier = 'TEST_IDENTIFIER';
        $formatted_depot_path = '//' . self::TEST_DEPOT . '/' . $identifier;
        $this->perforce->expects($this->any())->method('getComposerInformation')->with($this->equalTo($formatted_depot_path))->will($this->returnValue(['']));
        $this->driver->initialize();
        $result = $this->driver->hasComposerFile($identifier);
        self::assertTrue($result);
    }

    /**
     * Test that supports() simply return false.
     *
     * @covers \Composer\Repository\Vcs\PerforceDriver::supports
     */
    public function testSupportsReturnsFalseNoDeepCheck(): void
    {
        $this->expectOutputString('');
        self::assertFalse(PerforceDriver::supports($this->io, $this->config, 'existing.url'));
    }

    public function testCleanup(): void
    {
        $this->perforce->expects($this->once())->method('cleanupClientSpec');
        $this->driver->cleanup();
    }
}
