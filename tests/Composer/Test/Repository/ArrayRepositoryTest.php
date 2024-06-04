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
use Composer\Repository\RepositoryInterface;
use Composer\Test\TestCase;

class ArrayRepositoryTest extends TestCase
{
    public function testAddPackage(): void
    {
        $repo = new ArrayRepository;
        $repo->addPackage(self::getPackage('foo', '1'));

        self::assertCount(1, $repo);
    }

    public function testRemovePackage(): void
    {
        $package = self::getPackage('bar', '2');

        $repo = new ArrayRepository;
        $repo->addPackage(self::getPackage('foo', '1'));
        $repo->addPackage($package);

        self::assertCount(2, $repo);

        $repo->removePackage(self::getPackage('foo', '1'));

        self::assertCount(1, $repo);
        self::assertEquals([$package], $repo->getPackages());
    }

    public function testHasPackage(): void
    {
        $repo = new ArrayRepository;
        $repo->addPackage(self::getPackage('foo', '1'));
        $repo->addPackage(self::getPackage('bar', '2'));

        self::assertTrue($repo->hasPackage(self::getPackage('foo', '1')));
        self::assertFalse($repo->hasPackage(self::getPackage('bar', '1')));
    }

    public function testFindPackages(): void
    {
        $repo = new ArrayRepository();
        $repo->addPackage(self::getPackage('foo', '1'));
        $repo->addPackage(self::getPackage('bar', '2'));
        $repo->addPackage(self::getPackage('bar', '3'));

        $foo = $repo->findPackages('foo');
        self::assertCount(1, $foo);
        self::assertEquals('foo', $foo[0]->getName());

        $bar = $repo->findPackages('bar');
        self::assertCount(2, $bar);
        self::assertEquals('bar', $bar[0]->getName());
    }

    public function testAutomaticallyAddAliasedPackageButNotRemove(): void
    {
        $repo = new ArrayRepository();

        $package = self::getPackage('foo', '1');
        $alias = self::getAliasPackage($package, '2');

        $repo->addPackage($alias);

        self::assertCount(2, $repo);
        self::assertTrue($repo->hasPackage(self::getPackage('foo', '1')));
        self::assertTrue($repo->hasPackage(self::getPackage('foo', '2')));

        $repo->removePackage($alias);

        self::assertCount(1, $repo);
    }

    public function testSearch(): void
    {
        $repo = new ArrayRepository();

        $repo->addPackage(self::getPackage('foo', '1'));
        $repo->addPackage(self::getPackage('bar', '1'));

        self::assertSame(
            [['name' => 'foo', 'description' => null]],
            $repo->search('foo', RepositoryInterface::SEARCH_FULLTEXT)
        );

        self::assertSame(
            [['name' => 'bar', 'description' => null]],
            $repo->search('bar')
        );

        self::assertEmpty(
            $repo->search('foobar')
        );
    }

    public function testSearchWithPackageType(): void
    {
        $repo = new ArrayRepository();

        $repo->addPackage(self::getPackage('foo', '1', 'Composer\Package\CompletePackage'));
        $repo->addPackage(self::getPackage('bar', '1', 'Composer\Package\CompletePackage'));

        $package = self::getPackage('foobar', '1', 'Composer\Package\CompletePackage');
        $package->setType('composer-plugin');
        $repo->addPackage($package);

        self::assertSame(
            [['name' => 'foo', 'description' => null]],
            $repo->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'library')
        );

        self::assertEmpty($repo->search('bar', RepositoryInterface::SEARCH_FULLTEXT, 'package'));

        self::assertSame(
            [['name' => 'foobar', 'description' => null]],
            $repo->search('foo', 0, 'composer-plugin')
        );
    }

    public function testSearchWithAbandonedPackages(): void
    {
        $repo = new ArrayRepository();

        $package1 = self::getPackage('foo1', '1');
        $package1->setAbandoned(true);
        $repo->addPackage($package1);
        $package2 = self::getPackage('foo2', '1');
        $package2->setAbandoned('bar');
        $repo->addPackage($package2);

        self::assertSame(
            [
                ['name' => 'foo1', 'description' => null, 'abandoned' => true],
                ['name' => 'foo2', 'description' => null, 'abandoned' => 'bar'],
            ],
            $repo->search('foo')
        );
    }
}
