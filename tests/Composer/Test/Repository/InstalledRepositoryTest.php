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

use Composer\Repository\InstalledRepository;
use Composer\Repository\ArrayRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Package\Link;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class InstalledRepositoryTest extends TestCase
{
    public function testFindPackagesWithReplacersAndProviders(): void
    {
        $arrayRepoOne = new InstalledArrayRepository;
        $arrayRepoOne->addPackage($foo = self::getPackage('foo', '1'));
        $arrayRepoOne->addPackage($foo2 = self::getPackage('foo', '2'));

        $arrayRepoTwo = new InstalledArrayRepository;
        $arrayRepoTwo->addPackage($bar = self::getPackage('bar', '1'));
        $arrayRepoTwo->addPackage($bar2 = self::getPackage('bar', '2'));

        $foo->setReplaces(['provided' => new Link('foo', 'provided', new MatchAllConstraint())]);
        $bar2->setProvides(['provided' => new Link('bar', 'provided', new MatchAllConstraint())]);

        $repo = new InstalledRepository([$arrayRepoOne, $arrayRepoTwo]);

        self::assertEquals([$foo2], $repo->findPackagesWithReplacersAndProviders('foo', '2'));
        self::assertEquals([$bar], $repo->findPackagesWithReplacersAndProviders('bar', '1'));
        self::assertEquals([$foo, $bar2], $repo->findPackagesWithReplacersAndProviders('provided'));
    }

    public function testAddRepository(): void
    {
        $arrayRepoOne = new ArrayRepository;

        self::expectException('LogicException');

        new InstalledRepository([$arrayRepoOne]);
    }
}
