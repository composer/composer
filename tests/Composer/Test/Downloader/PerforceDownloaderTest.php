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

namespace Composer\Test\Downloader;

use Composer\Downloader\PerforceDownloader;
use Composer\Config;
use Composer\Repository\VcsRepository;
use Composer\IO\IOInterface;
use Composer\Test\TestCase;
use Composer\Factory;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDownloaderTest extends TestCase
{
    /** @var Config */
    protected $config;
    /** @var PerforceDownloader */
    protected $downloader;
    /** @var IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    protected $io;
    /** @var \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject */
    protected $package;
    /** @var \Composer\Test\Mock\ProcessExecutorMock */
    protected $processExecutor;
    /** @var string[] */
    protected $repoConfig;
    /** @var VcsRepository&\PHPUnit\Framework\MockObject\MockObject */
    protected $repository;
    /** @var string */
    protected $testPath;

    protected function setUp(): void
    {
        $this->testPath = self::getUniqueTmpDirectory();
        $this->repoConfig = $this->getRepoConfig();
        $this->config = $this->getConfig();
        $this->io = $this->getMockIoInterface();
        $this->processExecutor = $this->getProcessExecutorMock();
        $this->repository = $this->getMockRepository($this->repoConfig, $this->io, $this->config);
        $this->package = $this->getMockPackageInterface($this->repository);
        $this->downloader = new PerforceDownloader($this->io, $this->config, $this->processExecutor);
    }

    protected function getConfig(array $configOptions = [], bool $useEnvironment = false): Config
    {
        return parent::getConfig(array_merge(['home' => $this->testPath], $configOptions), $useEnvironment);
    }

    /**
     * @return IOInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function getMockIoInterface()
    {
        return $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function getMockPackageInterface(VcsRepository $repository)
    {
        $package = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $package->expects($this->any())->method('getRepository')->will($this->returnValue($repository));

        return $package;
    }

    /**
     * @return string[]
     */
    protected function getRepoConfig(): array
    {
        return ['url' => 'TEST_URL', 'p4user' => 'TEST_USER'];
    }

    /**
     * @param string[] $repoConfig
     * @return VcsRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function getMockRepository(array $repoConfig, IOInterface $io, Config $config)
    {
        $repository = $this->getMockBuilder('Composer\Repository\VcsRepository')
            ->onlyMethods(['getRepoConfig'])
            ->setConstructorArgs([$repoConfig, $io, $config, Factory::createHttpDownloader($io, $config)])
            ->getMock();
        $repository->expects($this->any())->method('getRepoConfig')->will($this->returnValue($repoConfig));

        return $repository;
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testInitPerforceInstantiatesANewPerforceObject(): void
    {
        $this->downloader->initPerforce($this->package, $this->testPath, 'SOURCE_REF');
    }

    public function testInitPerforceDoesNothingIfPerforceAlreadySet(): void
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
    public function testDoInstallWithTag(): void
    {
        //I really don't like this test but the logic of each Perforce method is tested in the Perforce class.  Really I am just enforcing workflow.
        $ref = 'SOURCE_REF@123';
        $label = 123;
        $this->package->expects($this->once())->method('getSourceReference')->will($this->returnValue($ref));
        $this->io->expects($this->once())->method('writeError')->with($this->stringContains('Cloning '.$ref));
        $perforceMethods = ['setStream', 'p4Login', 'writeP4ClientSpec', 'connectClient', 'syncCodeBase', 'cleanupClientSpec'];
        $perforce = $this->getMockBuilder('Composer\Util\Perforce')->disableOriginalConstructor()->getMock();
        $perforce->expects($this->once())->method('initializePath')->with($this->equalTo($this->testPath));
        $perforce->expects($this->once())->method('setStream')->with($this->equalTo($ref));
        $perforce->expects($this->once())->method('p4Login');
        $perforce->expects($this->once())->method('writeP4ClientSpec');
        $perforce->expects($this->once())->method('connectClient');
        $perforce->expects($this->once())->method('syncCodeBase')->with($label);
        $perforce->expects($this->once())->method('cleanupClientSpec');
        $this->downloader->setPerforce($perforce);
        $this->downloader->doInstall($this->package, $this->testPath, 'url');
    }

    /**
     * @depends testInitPerforceInstantiatesANewPerforceObject
     * @depends testInitPerforceDoesNothingIfPerforceAlreadySet
     */
    public function testDoInstallWithNoTag(): void
    {
        $ref = 'SOURCE_REF';
        $label = null;
        $this->package->expects($this->once())->method('getSourceReference')->will($this->returnValue($ref));
        $this->io->expects($this->once())->method('writeError')->with($this->stringContains('Cloning '.$ref));
        $perforceMethods = ['setStream', 'p4Login', 'writeP4ClientSpec', 'connectClient', 'syncCodeBase', 'cleanupClientSpec'];
        $perforce = $this->getMockBuilder('Composer\Util\Perforce')->disableOriginalConstructor()->getMock();
        $perforce->expects($this->once())->method('initializePath')->with($this->equalTo($this->testPath));
        $perforce->expects($this->once())->method('setStream')->with($this->equalTo($ref));
        $perforce->expects($this->once())->method('p4Login');
        $perforce->expects($this->once())->method('writeP4ClientSpec');
        $perforce->expects($this->once())->method('connectClient');
        $perforce->expects($this->once())->method('syncCodeBase')->with($label);
        $perforce->expects($this->once())->method('cleanupClientSpec');
        $this->downloader->setPerforce($perforce);
        $this->downloader->doInstall($this->package, $this->testPath, 'url');
    }
}
