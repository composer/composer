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

namespace Composer\Test\Package\Loader;

use Composer\Config;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\BasePackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackage;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use Composer\Util\ProcessExecutor;

class RootPackageLoaderTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     *
     * @return RootPackage|RootAliasPackage
     */
    protected function loadPackage(array $data): \Composer\Package\PackageInterface
    {
        $manager = $this->getMockBuilder('Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $processExecutor = new ProcessExecutor();
        $processExecutor->enableAsync();
        $guesser = new VersionGuesser($config, $processExecutor, new VersionParser());

        $loader = new RootPackageLoader($manager, $config, null, $guesser);

        return $loader->load($data);
    }

    public function testStabilityFlagsParsing(): void
    {
        $package = $this->loadPackage([
            'require' => [
                'foo/bar' => '~2.1.0-beta2',
                'bar/baz' => '1.0.x-dev as 1.2.0',
                'qux/quux' => '1.0.*@rc',
                'zux/complex' => '~1.0,>=1.0.2@dev',
                'or/op' => '^2.0@dev || ^2.0@dev',
                'multi/lowest-wins' => '^2.0@rc || >=3.0@dev , ~3.5@alpha',
                'or/op-without-flags' => 'dev-master || 2.0 , ~3.5-alpha',
                'or/op-without-flags2' => '3.0-beta || 2.0 , ~3.5-alpha',
            ],
            'minimum-stability' => 'alpha',
        ]);

        $this->assertEquals('alpha', $package->getMinimumStability());
        $this->assertEquals([
            'bar/baz' => BasePackage::STABILITY_DEV,
            'qux/quux' => BasePackage::STABILITY_RC,
            'zux/complex' => BasePackage::STABILITY_DEV,
            'or/op' => BasePackage::STABILITY_DEV,
            'multi/lowest-wins' => BasePackage::STABILITY_DEV,
            'or/op-without-flags' => BasePackage::STABILITY_DEV,
            'or/op-without-flags2' => BasePackage::STABILITY_ALPHA,
        ], $package->getStabilityFlags());
    }

    public function testNoVersionIsVisibleInPrettyVersion(): void
    {
        $manager = $this->getMockBuilder('Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $loader = new RootPackageLoader($manager, $config, null, new VersionGuesser($config, $process = $this->getProcessExecutorMock(), new VersionParser()));
        $process->expects([], false, ['return' => 1]);

        $package = $loader->load([]);

        $this->assertEquals("1.0.0.0", $package->getVersion());
        $this->assertEquals(RootPackage::DEFAULT_PRETTY_VERSION, $package->getPrettyVersion());
    }

    public function testPrettyVersionForRootPackageInVersionBranch(): void
    {
        // see #6845
        $manager = $this->getMockBuilder('Composer\\Repository\\RepositoryManager')->disableOriginalConstructor()->getMock();
        $versionGuesser = $this->getMockBuilder('Composer\\Package\\Version\\VersionGuesser')->disableOriginalConstructor()->getMock();
        $versionGuesser->expects($this->atLeastOnce())
            ->method('guessVersion')
            ->willReturn([
                'name' => 'A',
                'version' => '3.0.9999999.9999999-dev',
                'pretty_version' => '3.0-dev',
                'commit' => 'aabbccddee',
            ]);
        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $loader = new RootPackageLoader($manager, $config, null, $versionGuesser);
        $package = $loader->load([]);

        $this->assertEquals('3.0-dev', $package->getPrettyVersion());
    }

    public function testFeatureBranchPrettyVersion(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $manager = $this->getMockBuilder('Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* latest-production 38137d2f6c70e775e137b2d8a7a7d3eaebf7c7e5 Commit message\n  master 4f6ed96b0bc363d2aa4404c3412de1c011f67c66 Commit message\n",
            ],
            'git rev-list master..latest-production',
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $loader = new RootPackageLoader($manager, $config, null, new VersionGuesser($config, $process, new VersionParser()));
        $package = $loader->load(['require' => ['foo/bar' => 'self.version']]);

        $this->assertEquals("dev-master", $package->getPrettyVersion());
    }

    public function testNonFeatureBranchPrettyVersion(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }

        $manager = $this->getMockBuilder('Composer\\Repository\\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $process = $this->getProcessExecutorMock();
        $process->expects([
            [
                'cmd' => ['git', 'branch', '-a', '--no-color', '--no-abbrev', '-v'],
                'stdout' => "* latest-production 38137d2f6c70e775e137b2d8a7a7d3eaebf7c7e5 Commit message\n  master 4f6ed96b0bc363d2aa4404c3412de1c011f67c66 Commit message\n",
            ],
        ], true);

        $config = new Config;
        $config->merge(['repositories' => ['packagist' => false]]);
        $loader = new RootPackageLoader($manager, $config, null, new VersionGuesser($config, $process, new VersionParser()));
        $package = $loader->load(['require' => ['foo/bar' => 'self.version'], "non-feature-branches" => ["latest-.*"]]);

        $this->assertEquals("dev-latest-production", $package->getPrettyVersion());
    }
}
