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

namespace Composer\Repository;

use Composer\Repository\FilesystemRepository;
use Composer\Test\TestCase;

/**
 * @group slow
 */
class PearRepositoryTest extends TestCase
{
    /**
     * @var PearRepository
     */
    private $repository;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $remoteFilesystem;

    public function testComposerNonCompatibleRepositoryShouldSetIncludePath() {
        $url = 'pear.phpmd.org';
        $expectedPackages = array(
                    array('name' => 'pear-phpmd/PHP_PMD', 'version' => '1.3.3'),
                );

        $repoConfig = array(
            'url' => $url
        );

        $this->createRepository($repoConfig);

        foreach ($expectedPackages as $expectedPackage) {
            $package = $this->repository->findPackage($expectedPackage['name'], $expectedPackage['version']);
            $this->assertInstanceOf('Composer\Package\PackageInterface',
                $package,
                'Expected package ' . $expectedPackage['name'] . ', version ' . $expectedPackage['version'] .
                    ' not found in pear channel ' . $url
            );
            $this->assertSame(array('/'), $package->getIncludePaths());
        }
    }

    /**
     * @dataProvider repositoryDataProvider
     * @param string $url
     * @param array $expectedPackages
     */
    public function testRepositoryRead($url, array $expectedPackages)
    {
        $repoConfig = array(
            'url' => $url
        );

        $this->createRepository($repoConfig);

        foreach ($expectedPackages as $expectedPackage) {
            $this->assertInstanceOf('Composer\Package\PackageInterface',
                $this->repository->findPackage($expectedPackage['name'], $expectedPackage['version']),
                'Expected package ' . $expectedPackage['name'] . ', version ' . $expectedPackage['version'] .
                ' not found in pear channel ' . $url
            );
        }
    }

    public static function repositoryDataProvider()
    {
        return array(
            array(
                'pear.phpunit.de',
                array(
                    array('name' => 'pear-phpunit/PHPUnit_MockObject', 'version' => '1.1.1'),
                    array('name' => 'pear-phpunit/PHPUnit', 'version' => '3.6.10'),
                )
            ),
            array(
                'pear.php.net',
                array(
                    array('name' => 'pear-pear/PEAR', 'version' => '1.9.4'),
                )
            ),
            array(
                'pear.pdepend.org',
                array(
                    array('name' => 'pear-pdepend/PHP_Depend', 'version' => '1.0.5'),
                )
            ),
            array(
                'pear.phpmd.org',
                array(
                    array('name' => 'pear-phpmd/PHP_PMD', 'version' => '1.3.3'),
                )
            ),
            array(
                'pear.doctrine-project.org',
                array(
                    array('name' => 'pear-doctrine/DoctrineORM', 'version' => '2.2.2'),
                )
            ),
            array(
                'pear.symfony-project.com',
                array(
                    array('name' => 'pear-symfony/YAML', 'version' => '1.0.6'),
                )
            ),
            array(
                'pear.pirum-project.org',
                array(
                    array('name' => 'pear-pirum/Pirum', 'version' => '1.1.4'),
                )
            ),
            array(
                'packages.zendframework.com',
                array(
                    array('name' => 'pear-zf2/Zend_Code', 'version' => '2.0.0.0-beta3'),
                )
            ),
        );
    }

    private function createRepository($repoConfig)
    {
        $ioInterface = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();

        $config = new \Composer\Config();

        $this->remoteFilesystem = $this->getMockBuilder('Composer\Util\RemoteFilesystem')
            ->disableOriginalConstructor()
            ->getMock();

        $this->repository = new PearRepository($repoConfig, $ioInterface, $config, null);
    }

    protected function tearDown()
    {
        $this->repository = null;
        $this->remoteFilesystem = null;
    }
}