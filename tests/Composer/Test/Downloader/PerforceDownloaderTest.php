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

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDownloaderTest extends \PHPUnit_Framework_TestCase
{
    private $io;
    private $config;
    private $testPath;
    public static $repository;

    protected function setUp()
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
    }

    public function testInitPerforceGetRepoConfig()
    {
        $downloader = new PerforceDownloader($this->io, $this->config);
        $package = $this->getMock('Composer\Package\PackageInterface');
        $repoConfig = array('url' => 'TEST_URL', 'p4user' => 'TEST_USER');
        $repository = $this->getMock(
            'Composer\Repository\VcsRepository',
            array('getRepoConfig'),
            array($repoConfig, $this->io, $this->config)
        );
        $package->expects($this->at(0))
            ->method('getRepository')
            ->will($this->returnValue($repository));
        $repository->expects($this->at(0))
            ->method('getRepoConfig');
        $path = $this->testPath;
        $downloader->initPerforce($package, $path, 'SOURCE_REF');
    }

    public function testDoDownload()
    {
        $downloader = new PerforceDownloader($this->io, $this->config);
        $repoConfig = array('depot' => 'TEST_DEPOT', 'branch' => 'TEST_BRANCH', 'p4user' => 'TEST_USER');
        $port = 'TEST_PORT';
        $path = 'TEST_PATH';
        $process = $this->getmock('Composer\Util\ProcessExecutor');
        $perforce = $this->getMock(
            'Composer\Util\Perforce',
            array('setStream', 'queryP4User', 'writeP4ClientSpec', 'connectClient', 'syncCodeBase'),
            array($repoConfig, $port, $path, $process, true, 'TEST')
        );
        $ref = 'SOURCE_REF';
        $label = 'LABEL';
        $perforce->expects($this->at(0))
            ->method('setStream')
            ->with($this->equalTo($ref));
        $perforce->expects($this->at(1))
            ->method('queryP4User')
            ->with($this->io);
        $perforce->expects($this->at(2))
            ->method('writeP4ClientSpec');
        $perforce->expects($this->at(3))
            ->method('connectClient');
        $perforce->expects($this->at(4))
            ->method('syncCodeBase')
            ->with($this->equalTo($label));
        $downloader->setPerforce($perforce);
        $package = $this->getMock('Composer\Package\PackageInterface');
        $package->expects($this->at(0))
            ->method('getSourceReference')
            ->will($this->returnValue($ref));
        $package->expects($this->at(1))
            ->method('getPrettyVersion')
            ->will($this->returnValue($label));
        $path = $this->testPath;
        $downloader->doDownload($package, $path);
    }
}
