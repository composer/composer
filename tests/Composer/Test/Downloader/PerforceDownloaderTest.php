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

namespace Composer\Test\Downloader;

use Composer\Downloader\PerforceDownloader;
use Composer\Config;
use Composer\Repository\VcsRepository;
use Composer\IO\IOInterface;
use Composer\TestCase;
use Composer\Util\Filesystem;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDownloaderTest extends TestCase
{
    protected $config;
    /** @var PerforceDownloader */
    protected $downloader;
    protected $io;
    protected $package;
    protected $processExecutor;
    protected $repoConfig;
    protected $repository;
    protected $testPath;

    protected function setUp()
    {
        $this->testPath        = $this->getUniqueTmpDirectory();
        $this->repoConfig      = $this->getRepoConfig();
        $this->config          = $this->getConfig();
        $this->io              = $this->getMockIoInterface();
        $this->processExecutor = $this->getMockProcessExecutor();
        $this->repository      = $this->getMockRepository($this->repoConfig, $this->io, $this->config);
        $this->package         = $this->getMockPackageInterface($this->repository);
        $this->downloader      = new PerforceDownloader($this->io, $this->config, $this->processExecutor);
    }

    protected function tearDown()
    {
        $this->downloader = null;
        $this->package    = null;
        $this->repository = null;
        $this->io         = null;
        $this->config     = null;
        $this->repoConfig = null;
        if (is_dir($this->testPath)) {
            $fs = new Filesystem;
            $fs->removeDirectory($this->testPath);
        }
    }

    protected function getMockProcessExecutor()
    {
        return $this->getMock('Composer\Util\ProcessExecutor');
    }

    protected function getConfig()
    {
        $config = new Config();
        $settings = array('config' => array('home' => $this->testPath));
        $config->merge($settings);

        return $config;
    }

    protected function getMockIoInterface()
    {
        return $this->getMock('Composer\IO\IOInterface');
    }

    protected function getMockPackageInterface(VcsRepository $repository)
    {
        $package = $this->getMock('Composer\Package\PackageInterface');
        $package->expects($this->any())->method('getRepository')->will($this->returnValue($repository));

        return $package;
    }

    protected function getRepoConfig()
    {
        return array('url' => 'TEST_URL', 'p4user' => 'TEST_USER');
    }

    protected function getMockRepository(array $repoConfig, IOInterface $io, Config $config)
    {
        $class = 'Composer\Repository\VcsRepository';
        $methods = array('getRepoConfig');
        $args = array($repoConfig, $io, $config);
        $repository = $this->getMock($class, $methods, $args);
        $repository->expects($this->any())->method('getRepoConfig')->will($this->returnValue($repoConfig));

        return $repository;
    }

    public function testInitPerforceInstantiatesANewPerforceObject()
    {
        $this->downloader->initPerforce($this->package, $this->testPath, 'SOURCE_REF');
    }

    public function testInitPerforceDoesNothingIfPerforceAlreadySet()
    {
        $perforce = $this->getMockBuilder('Composer\Util\Perforce')->disableOriginalConstructor()->getMock();
        $this->downloader->setPerforce($perforce);
        $this->repository->expects($this->never())->method('getRepoConfig');
        $this->downloader->initPerforce($this->package, $this->testPath, 'SOURCE_REF');
    }

    /**
     * @depends testInitPerforceInstantiatesANewPerforceObject
     * @depends testInitPerforceDoesNothingIfPerforceAlreadySet
     */
    public function testDoDownloadWithTag()
    {
        //I really don't like this test but the logic of each Perforce method is tested in the Perforce class.  Really I am just enforcing workflow.
        $ref = 'SOURCE_REF@123';
        $label = 123;
        $this->package->expects($this->once())->method('getSourceReference')->will($this->returnValue($ref));
        $this->io->expects($this->once())->method('writeError')->with($this->stringContains('Cloning '.$ref));
        $perforceMethods = array('setStream', 'p4Login', 'writeP4ClientSpec', 'connectClient', 'syncCodeBase', 'cleanupClientSpec');
        $perforce = $this->getMockBuilder('Composer\Util\Perforce', $perforceMethods)->disableOriginalConstructor()->getMock();
        $perforce->expects($this->at(0))->method('initializePath')->with($this->equalTo($this->testPath));
        $perforce->expects($this->at(1))->method('setStream')->with($this->equalTo($ref));
        $perforce->expects($this->at(2))->method('p4Login');
        $perforce->expects($this->at(3))->method('writeP4ClientSpec');
        $perforce->expects($this->at(4))->method('connectClient');
        $perforce->expects($this->at(5))->method('syncCodeBase')->with($label);
        $perforce->expects($this->at(6))->method('cleanupClientSpec');
        $this->downloader->setPerforce($perforce);
        $this->downloader->doDownload($this->package, $this->testPath, 'url');
    }

    /**
     * @depends testInitPerforceInstantiatesANewPerforceObject
     * @depends testInitPerforceDoesNothingIfPerforceAlreadySet
     */
    public function testDoDownloadWithNoTag()
    {
        $ref = 'SOURCE_REF';
        $label = null;
        $this->package->expects($this->once())->method('getSourceReference')->will($this->returnValue($ref));
        $this->io->expects($this->once())->method('writeError')->with($this->stringContains('Cloning '.$ref));
        $perforceMethods = array('setStream', 'p4Login', 'writeP4ClientSpec', 'connectClient', 'syncCodeBase', 'cleanupClientSpec');
        $perforce = $this->getMockBuilder('Composer\Util\Perforce', $perforceMethods)->disableOriginalConstructor()->getMock();
        $perforce->expects($this->at(0))->method('initializePath')->with($this->equalTo($this->testPath));
        $perforce->expects($this->at(1))->method('setStream')->with($this->equalTo($ref));
        $perforce->expects($this->at(2))->method('p4Login');
        $perforce->expects($this->at(3))->method('writeP4ClientSpec');
        $perforce->expects($this->at(4))->method('connectClient');
        $perforce->expects($this->at(5))->method('syncCodeBase')->with($label);
        $perforce->expects($this->at(6))->method('cleanupClientSpec');
        $this->downloader->setPerforce($perforce);
        $this->downloader->doDownload($this->package, $this->testPath, 'url');
    }
}
