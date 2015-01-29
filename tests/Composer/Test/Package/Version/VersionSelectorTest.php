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

namespace Composer\Test\Package\Version;

use Composer\Package\Version\VersionSelector;
use Composer\Package\Version\VersionParser;

class VersionSelectorTest extends \PHPUnit_Framework_TestCase
{
    // A) multiple versions, get the latest one
    // B) targetPackageVersion will pass to pool
    // C) No results, throw exception

    public function testLatestVersionIsReturned()
    {
        $packageName = 'foobar';

        $package1 = $this->createMockPackage('1.2.1');
        $package2 = $this->createMockPackage('1.2.2');
        $package3 = $this->createMockPackage('1.2.0');
        $packages = array($package1, $package2, $package3);

        $pool = $this->createMockPool();
        $pool->expects($this->once())
            ->method('whatProvides')
            ->with($packageName, null, true)
            ->will($this->returnValue($packages));

        $versionSelector = new VersionSelector($pool);
        $best = $versionSelector->findBestCandidate($packageName);

        // 1.2.2 should be returned because it's the latest of the returned versions
        $this->assertEquals($package2, $best, 'Latest version should be 1.2.2');
    }

    public function testFalseReturnedOnNoPackages()
    {
        $pool = $this->createMockPool();
        $pool->expects($this->once())
            ->method('whatProvides')
            ->will($this->returnValue(array()));

        $versionSelector = new VersionSelector($pool);
        $best = $versionSelector->findBestCandidate('foobaz');
        $this->assertFalse($best, 'No versions are available returns false');
    }

    /**
     * @dataProvider getRecommendedRequireVersionPackages
     */
    public function testFindRecommendedRequireVersion($prettyVersion, $isDev, $stability, $expectedVersion, $branchAlias = null)
    {
        $pool = $this->createMockPool();
        $versionSelector = new VersionSelector($pool);
        $versionParser = new VersionParser();

        $package = $this->getMock('\Composer\Package\PackageInterface');
        $package->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue($prettyVersion));
        $package->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue($versionParser->normalize($prettyVersion)));
        $package->expects($this->any())
            ->method('isDev')
            ->will($this->returnValue($isDev));
        $package->expects($this->any())
            ->method('getStability')
            ->will($this->returnValue($stability));

        $branchAlias = $branchAlias === null ? array() : array('branch-alias' => array($prettyVersion => $branchAlias));
        $package->expects($this->any())
            ->method('getExtra')
            ->will($this->returnValue($branchAlias));

        $recommended = $versionSelector->findRecommendedRequireVersion($package);

        // assert that the recommended version is what we expect
        $this->assertEquals($expectedVersion, $recommended);
    }

    public function getRecommendedRequireVersionPackages()
    {
        return array(
            // real version, is dev package, stability, expected recommendation, [branch-alias]
            array('1.2.1', false, 'stable', '~1.2'),
            array('1.2', false, 'stable', '~1.2'),
            array('v1.2.1', false, 'stable', '~1.2'),
            array('3.1.2-pl2', false, 'stable', '~3.1'),
            array('3.1.2-patch', false, 'stable', '~3.1'),
            array('0.1.0', false, 'stable', '0.1.*'),
            array('0.1.3', false, 'stable', '0.1.*'),
            array('0.0.3', false, 'stable', '0.0.3.*'),
            array('0.0.3-alpha', false, 'alpha', '0.0.3.*@alpha'),
            array('2.0-beta.1', false, 'beta', '~2.0@beta'),
            array('3.1.2-alpha5', false, 'alpha', '~3.1@alpha'),
            array('3.0-RC2', false, 'RC', '~3.0@RC'),
            // date-based versions are not touched at all
            array('v20121020', false, 'stable', 'v20121020'),
            array('v20121020.2', false, 'stable', 'v20121020.2'),
            // dev packages without alias are not touched at all
            array('dev-master', true, 'dev', 'dev-master'),
            array('3.1.2-dev', true, 'dev', '3.1.2-dev'),
            // dev packages with alias inherit the alias
            array('dev-master', true, 'dev', '~2.1@dev', '2.1.x-dev'),
            array('dev-master', true, 'dev', '~2.1@dev', '2.1-dev'),
            array('dev-master', true, 'dev', '~2.1@dev', '2.1.3.x-dev'),
            array('dev-master', true, 'dev', '~2.0@dev', '2.x-dev'),
            // numeric alias
            array('3.x-dev', true, 'dev', '~3.0@dev', '3.0.x-dev'),
            array('3.x-dev', true, 'dev', '~3.0@dev', '3.0-dev'),
        );
    }

    private function createMockPackage($version)
    {
        $package = $this->getMock('\Composer\Package\PackageInterface');
        $package->expects($this->any())
            ->method('getVersion')
            ->will($this->returnValue($version));

        return $package;
    }

    private function createMockPool()
    {
        return $this->getMock('Composer\DependencyResolver\Pool', array(), array(), '', true);
    }
}
