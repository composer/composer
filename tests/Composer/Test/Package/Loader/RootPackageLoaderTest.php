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
        $processExecutor = new ProcessExecutorMock(function($command, &$output = null, $cwd = null) use ($self, $commitHash) {
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
        $processExecutor = new ProcessExecutorMock(function($command, &$output = null, $cwd = null) use ($self) {
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
}
