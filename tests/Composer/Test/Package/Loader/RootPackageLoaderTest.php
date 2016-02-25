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

namespace Composer\Test\Package\Loader;

use Composer\Config;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;

class RootPackageLoaderTest extends \PHPUnit_Framework_TestCase
{
    protected function loadPackage($data)
    {
        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));

        $loader = new RootPackageLoader($manager, $config);

        return $loader->load($data);
    }

    public function testStabilityFlagsParsing()
    {
        $package = $this->loadPackage(array(
            'require' => array(
                'foo/bar' => '~2.1.0-beta2',
                'bar/baz' => '1.0.x-dev as 1.2.0',
                'qux/quux' => '1.0.*@rc',
                'zux/complex' => '~1.0,>=1.0.2@dev',
                'or/op' => '^2.0@dev || ^2.0@dev',
                'multi/lowest-wins' => '^2.0@rc || >=3.0@dev , ~3.5@alpha',
            ),
            'minimum-stability' => 'alpha',
        ));

        $this->assertEquals('alpha', $package->getMinimumStability());
        $this->assertEquals(array(
            'bar/baz' => BasePackage::STABILITY_DEV,
            'qux/quux' => BasePackage::STABILITY_RC,
            'zux/complex' => BasePackage::STABILITY_DEV,
            'or/op' => BasePackage::STABILITY_DEV,
            'multi/lowest-wins' => BasePackage::STABILITY_DEV,
        ), $package->getStabilityFlags());
    }

    public function testNoVersionIsVisibleInPrettyVersion()
    {
        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $executor
            ->expects($this->any())
            ->method('execute')
            ->willReturn(null)
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, new VersionGuesser($config, $executor, new VersionParser()));
        $package = $loader->load(array());

        $this->assertEquals("1.0.0.0", $package->getVersion());
        $this->assertEquals("No version set (parsed as 1.0.0)", $package->getPrettyVersion());
    }

    public function testFeatureBranchPrettyVersion()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch --no-color --no-abbrev -v', $command);
                $output = "* latest-production 38137d2f6c70e775e137b2d8a7a7d3eaebf7c7e5 Commit message\n  master 4f6ed96b0bc363d2aa4404c3412de1c011f67c66 Commit message\n";

                return 0;
            })
        ;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git rev-list master..latest-production', $command);
                $output = "";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, new VersionGuesser($config, $executor, new VersionParser()));
        $package = $loader->load(array('require' => array('foo/bar' => 'self.version')));

        $this->assertEquals("dev-master", $package->getPrettyVersion());
    }

    public function testNonFeatureBranchPrettyVersion()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch --no-color --no-abbrev -v', $command);
                $output = "* latest-production 38137d2f6c70e775e137b2d8a7a7d3eaebf7c7e5 Commit message\n  master 4f6ed96b0bc363d2aa4404c3412de1c011f67c66 Commit message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, new VersionGuesser($config, $executor, new VersionParser()));
        $package = $loader->load(array('require' => array('foo/bar' => 'self.version'), "non-feature-branches" => array("latest-.*")));

        $this->assertEquals("dev-latest-production", $package->getPrettyVersion());
    }
}
