<?php


namespace Composer\Test\Repository;


use Composer\Package\CompletePackageInterface;
use Composer\Repository\LocalRepository;

class LocalRepositoryTest extends \PHPUnit_Framework_TestCase
{

    public function testFindPackages()
    {
        $localRepository = new LocalRepository(
            ['url' => __DIR__ . '/Fixtures/local'],
            $this->getMock('Composer\IO\IOInterface'),
            $configMock = $this->getMock('Composer\Config')
        );
        $packages = $localRepository->getPackages();
        $packageNames = array_map(
            function (CompletePackageInterface $package) {
                return $package->getName();
                },
            $packages
        );

        $this->assertContains('test/proj1', $packageNames);
        $this->assertContains('test/proj2', $packageNames);
        $this->assertContains('test/proj3a', $packageNames);
        $this->assertNotContains('test/proj3b', $packageNames);
        $this->assertNotContains('test/no_local_dependency', $packageNames);
        $this->assertNotContains('test/composer_installer', $packageNames);
    }

}
 