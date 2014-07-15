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
