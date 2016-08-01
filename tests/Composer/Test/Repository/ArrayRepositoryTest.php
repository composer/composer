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

namespace Composer\Test\Repository;

use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\TestCase;

class ArrayRepositoryTest extends TestCase
{
    public function testAddPackage()
    {
        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));

        $this->assertEquals(1, count($repo));
    }

    public function testRemovePackage()
    {
        $package = $this->getPackage('bar', '2');

        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($package);

        $this->assertEquals(2, count($repo));

        $repo->removePackage($this->getPackage('foo', '1'));

        $this->assertEquals(1, count($repo));
        $this->assertEquals(array($package), $repo->getPackages());
    }

    public function testHasPackage()
    {
        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($this->getPackage('bar', '2'));

        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '1')));
        $this->assertFalse($repo->hasPackage($this->getPackage('bar', '1')));
    }

    public function testFindPackages()
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

    public function testAutomaticallyAddAliasedPackage()
    {
        $repo = new ArrayRepository();

        $package = $this->getPackage('foo', '1');
        $alias = $this->getAliasPackage($package, '2');

        $repo->addPackage($alias);

        $this->assertEquals(2, count($repo));
        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '1')));
        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '2')));
    }

    public function testSearch()
    {
        $repo = new ArrayRepository();

        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($this->getPackage('bar', '1'));

        $this->assertSame(
            array(array('name' => 'foo', 'description' => null)),
            $repo->search('foo', RepositoryInterface::SEARCH_FULLTEXT)
        );

        $this->assertSame(
            array(array('name' => 'bar', 'description' => null)),
            $repo->search('bar')
        );

        $this->assertEmpty(
            $repo->search('foobar')
        );
    }

    public function testSearchWithPackageType()
    {
        $repo = new ArrayRepository();

        $repo->addPackage($this->getPackage('foo', '1', 'Composer\Package\CompletePackage'));
        $repo->addPackage($this->getPackage('bar', '1', 'Composer\Package\CompletePackage'));

        $package = $this->getPackage('foobar', '1', 'Composer\Package\CompletePackage');
        $package->setType('composer-plugin');
        $repo->addPackage($package);

        $this->assertSame(
            array(array('name' => 'foo', 'description' => null)),
            $repo->search('foo', RepositoryInterface::SEARCH_FULLTEXT, 'library')
        );

        $this->assertEmpty($repo->search('bar', RepositoryInterface::SEARCH_FULLTEXT, 'package'));

        $this->assertSame(
            array(array('name' => 'foobar', 'description' => null)),
            $repo->search('foo', 0, 'composer-plugin')
        );
    }
}
