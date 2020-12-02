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

namespace Composer\Test\Util;

use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Test\TestCase;
use Composer\Util\PackageSorter;
use Composer\Semver\Constraint\MatchAllConstraint;

class PackageSorterTest extends TestCase
{
    public function testSortingDoesNothingWithNoDependencies()
    {
        $packages[] = $this->createPackage('foo/bar1', array());
        $packages[] = $this->createPackage('foo/bar2', array());
        $packages[] = $this->createPackage('foo/bar3', array());
        $packages[] = $this->createPackage('foo/bar4', array());

        $sortedPackages = PackageSorter::sortPackages($packages);

        self::assertSame($packages, $sortedPackages);
    }

    public function sortingOrdersDependenciesHigherThanPackageDataProvider()
    {
        return array(
            'one package is dep' => array(
                array(
                    $this->createPackage('foo/bar1', array('foo/bar4')),
                    $this->createPackage('foo/bar2', array('foo/bar4')),
                    $this->createPackage('foo/bar3', array('foo/bar4')),
                    $this->createPackage('foo/bar4', array()),
                ),
                array(
                    'foo/bar4',
                    'foo/bar1',
                    'foo/bar2',
                    'foo/bar3',
                ),
            ),
            'one package has more deps' => array(
                array(
                    $this->createPackage('foo/bar1', array('foo/bar2')),
                    $this->createPackage('foo/bar2', array('foo/bar4')),
                    $this->createPackage('foo/bar3', array('foo/bar4')),
                    $this->createPackage('foo/bar4', array()),
                ),
                array(
                    'foo/bar4',
                    'foo/bar2',
                    'foo/bar1',
                    'foo/bar3',
                ),
            ),
            'package is required by many, but requires one other' => array(
                array(
                    $this->createPackage('foo/bar1', array('foo/bar3')),
                    $this->createPackage('foo/bar2', array('foo/bar3')),
                    $this->createPackage('foo/bar3', array('foo/bar4')),
                    $this->createPackage('foo/bar4', array()),
                    $this->createPackage('foo/bar5', array('foo/bar3')),
                    $this->createPackage('foo/bar6', array('foo/bar3')),
                ),
                array(
                    'foo/bar4',
                    'foo/bar3',
                    'foo/bar1',
                    'foo/bar2',
                    'foo/bar5',
                    'foo/bar6',
                ),
            ),
            'one package has many requires' => array(
                array(
                    $this->createPackage('foo/bar1', array('foo/bar2')),
                    $this->createPackage('foo/bar2', array()),
                    $this->createPackage('foo/bar3', array('foo/bar4')),
                    $this->createPackage('foo/bar4', array()),
                    $this->createPackage('foo/bar5', array('foo/bar2')),
                    $this->createPackage('foo/bar6', array('foo/bar2')),
                ),
                array(
                    'foo/bar2',
                    'foo/bar4',
                    'foo/bar1',
                    'foo/bar3',
                    'foo/bar5',
                    'foo/bar6',
                ),
            ),
        );
    }

    /**
     * @dataProvider sortingOrdersDependenciesHigherThanPackageDataProvider
     * @param array $packages
     * @param array $expectedOrderedList
     */
    public function testSortingOrdersDependenciesHigherThanPackage($packages, $expectedOrderedList)
    {
        $sortedPackages = PackageSorter::sortPackages($packages);
        $sortedPackageNames = array_map(function ($package) {
            return $package->getName();
        }, $sortedPackages);

        self::assertSame($expectedOrderedList, $sortedPackageNames);
    }

    private function createPackage($name, $requires)
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');

        $links = array();
        foreach ($requires as $requireName) {
            $links[] = new Link($package->getName(), $requireName, new MatchAllConstraint);
        }
        $package->setRequires($links);

        return $package;
    }
}
