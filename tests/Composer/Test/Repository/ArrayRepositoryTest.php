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
        $repo->addPackage($this->getPackage('foo', '1'));

        $this->assertCount(1, $repo);
    }

    public function testRemovePackage(): void
    {
        $package = $this->getPackage('bar', '2');

        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($package);

        $this->assertCount(2, $repo);

        $repo->removePackage($this->getPackage('foo', '1'));

        $this->assertCount(1, $repo);
        $this->assertEquals([$package], $repo->getPackages());
    }

    public function testHasPackage(): void
    {
        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($this->getPackage('bar', '2'));

        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '1')));
        $this->assertFalse($repo->hasPackage($this->getPackage('bar', '1')));
    }

    public function testFindPackages(): void
    {
        $repo = new ArrayRepository();
        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($this->getPackage('bar', '2'));
        $repo->addPackage($this->getPackage('bar', '3'));

        $foo = $repo->findPackages('foo');
        $this->assertCount(1, $foo);
        $this->assertEquals('foo', $foo[0]->getName());

        $bar = $repo->findPackages('bar');
        $this->assertCount(2, $bar);
        $this->assertEquals('bar', $bar[0]->getName());
    }

    public function testAutomaticallyAddAliasedPackageButNotRemove(): void
    {
        $repo = new ArrayRepository();

        $package = $this->getPackage('foo', '1');
        $alias = $this->getAliasPackage($package, '2');

        $repo->addPackage($alias);

        $this->assertCount(2, $repo);
        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '1')));
        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '2')));

        $repo->removePackage($alias);

        $this->assertCount(1, $repo);
    }

    public function testSearch(): void
    {
        $repo = new ArrayRepository();

        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($this->getPackage('bar', '1'));

        $this->assertSame(
            [['name' => 'foo', 'description' => null]],
            $repo->search('foo', RepositoryInterface::SEARCH_FULLTEXT)
        );

        $this->assertSame(
            [['name' => 'bar', 'description' => null]],
            $repo->search('bar')
        );

        $this->assertEmpty(
            $repo->search('foobar')
        );
    }

    public function testSearchWithPackageType(): void
    {
        $repo = new ArrayRepository();

        $repo->addPackage($this->getPackage('foo', '1', 'Composer\Package\CompletePackage'));
        $repo->addPackage($this->getPackage('bar', '1', 'Composer\Package\CompletePackage'));

        $package = $this->getPackage('foobar', '1', 'Composer\Package\CompletePackage');
        $package->setType('composer-plugin');
        $repo->addPackage($package);

        $this->assertSame(
            [['name' => 'foo', 'description' => null]],
            $repo->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'library')
        );

        $this->assertEmpty($repo->search('bar', RepositoryInterface::SEARCH_FULLTEXT, 'package'));

        $this->assertSame(
            [['name' => 'foobar', 'description' => null]],
            $repo->search('foo', 0, 'composer-plugin')
        );
    }

    public function testSearchWithAbandonedPackages(): void
    {
        $repo = new ArrayRepository();

        $package1 = $this->getPackage('foo1', '1');
        $package1->setAbandoned(true);
        $repo->addPackage($package1);
        $package2 = $this->getPackage('foo2', '1');
        $package2->setAbandoned('bar');
        $repo->addPackage($package2);

        $this->assertSame(
            [
                ['name' => 'foo1', 'description' => null, 'abandoned' => true],
                ['name' => 'foo2', 'description' => null, 'abandoned' => 'bar'],
            ],
            $repo->search('foo')
        );
    }
}
