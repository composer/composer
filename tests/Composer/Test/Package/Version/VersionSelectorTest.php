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
    public function testFindRecommendedRequireVersion($prettyVersion, $isDev, $stability, $expectedVersion)
    {
        $pool = $this->createMockPool();
        $versionSelector = new VersionSelector($pool);

        $package = $this->getMock('\Composer\Package\PackageInterface');
        $package->expects($this->any())
            ->method('getPrettyVersion')
            ->will($this->returnValue($prettyVersion));
        $package->expects($this->any())
            ->method('isDev')
            ->will($this->returnValue($isDev));
        $package->expects($this->any())
            ->method('getStability')
            ->will($this->returnValue($stability));

        $recommended = $versionSelector->findRecommendedRequireVersion($package);

        // assert that the recommended version is what we expect
        $this->assertEquals($expectedVersion, $recommended);
    }

    public function getRecommendedRequireVersionPackages()
    {
        return array(
            // real version, is dev package, stability, expected recommendation
            array('1.2.1', false, 'stable', '~1.2'),
            array('1.2', false, 'stable', '~1.2'),
            array('v1.2.1', false, 'stable', '~1.2'),
            array('3.1.2-pl2', false, 'stable', '~3.1'),
            array('3.1.2-patch', false, 'stable', '~3.1'),
            // for non-stable versions, we add ~, but don't try the (1.2.1 -> 1.2) transformation
            array('2.0-beta.1', false, 'beta', '~2.0-beta.1'),
            array('3.1.2-alpha5', false, 'alpha', '~3.1.2-alpha5'),
            array('3.0-RC2', false, 'RC', '~3.0-RC2'),
            // dev packages are not touched at all
            array('dev-master', true, 'dev', 'dev-master'),
            array('3.1.2-dev', true, 'dev', '3.1.2-dev'),
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
