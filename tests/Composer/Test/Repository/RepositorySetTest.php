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

namespace Composer\Test\Repository;

use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositorySet;
use Composer\Test\TestCase;

class RepositorySetTest extends TestCase
{
    public function testPreloadPackagesDoesNotQueryLowerPriorityRepositoriesForFoundNames(): void
    {
        $first = new ArrayRepository();
        $first->addPackage(self::getPackage('acme/private', '1.0.0'));

        $second = $this->getMockBuilder(ArrayRepository::class)->onlyMethods(['loadPackages'])->getMock();
        $second->expects(self::once())
            ->method('loadPackages')
            ->with(['foo/bar' => null], self::anything(), self::anything())
            ->willReturn(['namesFound' => [], 'packages' => []]);

        $third = new ArrayRepository();
        $third->addPackage(self::getPackage('foo/bar', '1.0.0'));

        $fourth = $this->getMockBuilder(ArrayRepository::class)->onlyMethods(['loadPackages'])->getMock();
        $fourth->expects(self::never())->method('loadPackages');

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new CompositeRepository([$first, $second, $third, $fourth]));

        $repositorySet->preloadPackages(['acme/private', 'foo/bar']);
    }
}
