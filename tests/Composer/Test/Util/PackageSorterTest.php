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

namespace Composer\Test\Util;

use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Test\TestCase;
use Composer\Util\PackageSorter;
use Composer\Semver\Constraint\MatchAllConstraint;

class PackageSorterTest extends TestCase
{
    public function testSortingDoesNothingWithNoDependencies(): void
    {
        $packages[] = $this->createPackage('foo/bar1', []);
        $packages[] = $this->createPackage('foo/bar2', []);
        $packages[] = $this->createPackage('foo/bar3', []);
        $packages[] = $this->createPackage('foo/bar4', []);

        $sortedPackages = PackageSorter::sortPackages($packages);

        self::assertSame($packages, $sortedPackages);
    }

    public function sortingOrdersDependenciesHigherThanPackageDataProvider(): array
    {
        return [
            'one package is dep' => [
                [
                    $this->createPackage('foo/bar1', ['foo/bar4']),
                    $this->createPackage('foo/bar2', ['foo/bar4']),
                    $this->createPackage('foo/bar3', ['foo/bar4']),
                    $this->createPackage('foo/bar4', []),
                ],
                [
                    'foo/bar4',
                    'foo/bar1',
                    'foo/bar2',
                    'foo/bar3',
                ],
            ],
            'one package has more deps' => [
                [
                    $this->createPackage('foo/bar1', ['foo/bar2']),
                    $this->createPackage('foo/bar2', ['foo/bar4']),
                    $this->createPackage('foo/bar3', ['foo/bar4']),
                    $this->createPackage('foo/bar4', []),
                ],
                [
                    'foo/bar4',
                    'foo/bar2',
                    'foo/bar1',
                    'foo/bar3',
                ],
            ],
            'package is required by many, but requires one other' => [
                [
                    $this->createPackage('foo/bar1', ['foo/bar3']),
                    $this->createPackage('foo/bar2', ['foo/bar3']),
                    $this->createPackage('foo/bar3', ['foo/bar4']),
                    $this->createPackage('foo/bar4', []),
                    $this->createPackage('foo/bar5', ['foo/bar3']),
                    $this->createPackage('foo/bar6', ['foo/bar3']),
                ],
                [
                    'foo/bar4',
                    'foo/bar3',
                    'foo/bar1',
                    'foo/bar2',
                    'foo/bar5',
                    'foo/bar6',
                ],
            ],
            'one package has many requires' => [
                [
                    $this->createPackage('foo/bar1', ['foo/bar2']),
                    $this->createPackage('foo/bar2', []),
                    $this->createPackage('foo/bar3', ['foo/bar4']),
                    $this->createPackage('foo/bar4', []),
                    $this->createPackage('foo/bar5', ['foo/bar2']),
                    $this->createPackage('foo/bar6', ['foo/bar2']),
                ],
                [
                    'foo/bar2',
                    'foo/bar4',
                    'foo/bar1',
                    'foo/bar3',
                    'foo/bar5',
                    'foo/bar6',
                ],
            ],
            'circular deps sorted alphabetically if weighted equally' => [
                [
                    $this->createPackage('foo/bar1', ['circular/part1']),
                    $this->createPackage('foo/bar2', ['circular/part2']),
                    $this->createPackage('circular/part1', ['circular/part2']),
                    $this->createPackage('circular/part2', ['circular/part1']),
                ],
                [
                    'circular/part1',
                    'circular/part2',
                    'foo/bar1',
                    'foo/bar2',
                ],
            ],
            'equal weight sorted alphabetically' => [
                [
                    $this->createPackage('foo/bar10', ['foo/dep']),
                    $this->createPackage('foo/bar2', ['foo/dep']),
                    $this->createPackage('foo/baz', ['foo/dep']),
                    $this->createPackage('foo/dep', []),
                ],
                [
                    'foo/dep',
                    'foo/bar2',
                    'foo/bar10',
                    'foo/baz',
                ],
            ],
            'pre-weighted packages bumped to top incl their deps' => [
                [
                    $this->createPackage('foo/bar', ['foo/dep']),
                    $this->createPackage('foo/bar2', ['foo/dep2']),
                    $this->createPackage('foo/dep', []),
                    $this->createPackage('foo/dep2', []),
                ],
                [
                    'foo/dep',
                    'foo/bar',
                    'foo/dep2',
                    'foo/bar2',
                ],
                [
                    'foo/bar' => -1000,
                ],
            ],
        ];
    }

    /**
     * @dataProvider sortingOrdersDependenciesHigherThanPackageDataProvider
     *
     * @param Package[] $packages
     * @param string[]  $expectedOrderedList
     * @param array<string, int> $weights
     */
    public function testSortingOrdersDependenciesHigherThanPackage(array $packages, array $expectedOrderedList, array $weights = []): void
    {
        $sortedPackages = PackageSorter::sortPackages($packages, $weights);
        $sortedPackageNames = array_map(static function ($package): string {
            return $package->getName();
        }, $sortedPackages);

        self::assertSame($expectedOrderedList, $sortedPackageNames);
    }

    /**
     * @param string[] $requires
     */
    private function createPackage(string $name, array $requires): Package
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');

        $links = [];
        foreach ($requires as $requireName) {
            $links[$requireName] = new Link($package->getName(), $requireName, new MatchAllConstraint);
        }
        $package->setRequires($links);

        return $package;
    }
}
