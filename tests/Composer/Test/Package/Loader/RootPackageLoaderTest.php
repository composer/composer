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
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Repository\RepositoryManager;

class RootPackageLoaderTest extends \PHPUnit_Framework_TestCase
{
    public function testDetachedHeadBecomesDevHash()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $self = $this;

        /* Can do away with this mock object when https://github.com/sebastianbergmann/phpunit-mock-objects/issues/81 is fixed */
        $processExecutor = new ProcessExecutorMock(function ($command, &$output = null, $cwd = null) use ($self, $commitHash) {
            if (0 === strpos($command, 'git describe')) {
                // simulate not being on a tag
                return 1;
            }

            $self->assertStringStartsWith('git branch', $command);

            $output = "* (no branch) $commitHash Commit message\n";

            return 0;
        });

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, $processExecutor);
        $package = $loader->load(array());

        $this->assertEquals("dev-$commitHash", $package->getVersion());
    }

    public function testTagBecomesVersion()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $self = $this;

        /* Can do away with this mock object when https://github.com/sebastianbergmann/phpunit-mock-objects/issues/81 is fixed */
        $processExecutor = new ProcessExecutorMock(function ($command, &$output = null, $cwd = null) use ($self) {
            $self->assertEquals('git describe --exact-match --tags', $command);

            $output = "v2.0.5-alpha2";

            return 0;
        });

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, $processExecutor);
        $package = $loader->load(array());

        $this->assertEquals("2.0.5.0-alpha2", $package->getVersion());
    }

    public function testInvalidTagBecomesVersion()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $self = $this;

        /* Can do away with this mock object when https://github.com/sebastianbergmann/phpunit-mock-objects/issues/81 is fixed */
        $processExecutor = new ProcessExecutorMock(function ($command, &$output = null, $cwd = null) use ($self) {
            if ('git describe --exact-match --tags' === $command) {
                $output = "foo-bar";

                return 0;
            }

            $output = "* foo 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n";

            return 0;
        });

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, $processExecutor);
        $package = $loader->load(array());

        $this->assertEquals("dev-foo", $package->getVersion());
    }

    protected function loadPackage($data)
    {
        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $processExecutor = new ProcessExecutorMock(function ($command, &$output = null, $cwd = null) {
            return 1;
        });

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
                'zux/complex' => '~1.0,>=1.0.2@dev'
            ),
            'minimum-stability' => 'alpha',
        ));

        $this->assertEquals('alpha', $package->getMinimumStability());
        $this->assertEquals(array(
            'bar/baz' => BasePackage::STABILITY_DEV,
            'qux/quux' => BasePackage::STABILITY_RC,
            'zux/complex' => BasePackage::STABILITY_DEV,
        ), $package->getStabilityFlags());
    }

    public function testFeatureBranchPrettyVersion()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $manager = $this->getMockBuilder('\\Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $self = $this;

        /* Can do away with this mock object when https://github.com/sebastianbergmann/phpunit-mock-objects/issues/81 is fixed */
        $processExecutor = new ProcessExecutorMock(function ($command, &$output = null, $cwd = null) use ($self) {
            if (0 === strpos($command, 'git rev-list')) {
                $output = "";

                return 0;
            }

            if ('git branch --no-color --no-abbrev -v' !== $command) {
                return 1; //0;
            }

            $self->assertEquals('git branch --no-color --no-abbrev -v', $command);

            $output = "* latest-production 38137d2f6c70e775e137b2d8a7a7d3eaebf7c7e5 Commit message\n  master 4f6ed96b0bc363d2aa4404c3412de1c011f67c66 Commit message\n";

            return 0;
        });

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, $processExecutor);
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
            ->getMock();

        $self = $this;

        /* Can do away with this mock object when https://github.com/sebastianbergmann/phpunit-mock-objects/issues/81 is fixed */
        $processExecutor = new ProcessExecutorMock(function ($command, &$output = null, $cwd = null) use ($self) {
            if (0 === strpos($command, 'git rev-list')) {
                $output = "";

                return 0;
            }

            if ('git branch --no-color --no-abbrev -v' !== $command) {
                return 1; //0;
            }

            $self->assertEquals('git branch --no-color --no-abbrev -v', $command);

            $output = "* latest-production 38137d2f6c70e775e137b2d8a7a7d3eaebf7c7e5 Commit message\n  master 4f6ed96b0bc363d2aa4404c3412de1c011f67c66 Commit message\n";

            return 0;
        });

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $loader = new RootPackageLoader($manager, $config, null, $processExecutor);
        $package = $loader->load(array('require' => array('foo/bar' => 'self.version'), "non-feature-branches" => array("latest-.*")));

        $this->assertEquals("dev-latest-production", $package->getPrettyVersion());
    }
}
