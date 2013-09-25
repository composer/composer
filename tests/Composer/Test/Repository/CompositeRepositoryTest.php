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

use Composer\Repository\CompositeRepository;
use Composer\Repository\ArrayRepository;
use Composer\TestCase;

class CompositeRepositoryTest extends TestCase
{
    public function testHasPackage()
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage($this->getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage($this->getPackage('bar', '1'));

        $repo = new CompositeRepository(array($arrayRepoOne, $arrayRepoTwo));

        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '1')), "Should have package 'foo/1'");
        $this->assertTrue($repo->hasPackage($this->getPackage('bar', '1')), "Should have package 'bar/1'");

        $this->assertFalse($repo->hasPackage($this->getPackage('foo', '2')), "Should not have package 'foo/2'");
        $this->assertFalse($repo->hasPackage($this->getPackage('bar', '2')), "Should not have package 'bar/2'");
    }

    public function testFindPackage()
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage($this->getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage($this->getPackage('bar', '1'));

        $repo = new CompositeRepository(array($arrayRepoOne, $arrayRepoTwo));

        $this->assertEquals('foo', $repo->findPackage('foo', '1')->getName(), "Should find package 'foo/1' and get name of 'foo'");
        $this->assertEquals('1', $repo->findPackage('foo', '1')->getPrettyVersion(), "Should find package 'foo/1' and get pretty version of '1'");
        $this->assertEquals('bar', $repo->findPackage('bar', '1')->getName(), "Should find package 'bar/1' and get name of 'bar'");
        $this->assertEquals('1', $repo->findPackage('bar', '1')->getPrettyVersion(), "Should find package 'bar/1' and get pretty version of '1'");
        $this->assertNull($repo->findPackage('foo', '2'), "Should not find package 'foo/2'");
    }

    public function testFindPackages()
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage($this->getPackage('foo', '1'));
        $arrayRepoOne->addPackage($this->getPackage('foo', '2'));
        $arrayRepoOne->addPackage($this->getPackage('bat', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage($this->getPackage('bar', '1'));
        $arrayRepoTwo->addPackage($this->getPackage('bar', '2'));
        $arrayRepoTwo->addPackage($this->getPackage('foo', '3'));

        $repo = new CompositeRepository(array($arrayRepoOne, $arrayRepoTwo));

        $bats = $repo->findPackages('bat');
        $this->assertCount(1, $bats, "Should find one instance of 'bats' (defined in just one repository)");
        $this->assertEquals('bat', $bats[0]->getName(), "Should find packages named 'bat'");

        $bars = $repo->findPackages('bar');
        $this->assertCount(2, $bars, "Should find two instances of 'bar' (both defined in the same repository)");
        $this->assertEquals('bar', $bars[0]->getName(), "Should find packages named 'bar'");

        $foos = $repo->findPackages('foo');
        $this->assertCount(3, $foos, "Should find three instances of 'foo' (two defined in one repository, the third in the other)");
        $this->assertEquals('foo', $foos[0]->getName(), "Should find packages named 'foo'");
    }

    public function testGetPackages()
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage($this->getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage($this->getPackage('bar', '1'));

        $repo = new CompositeRepository(array($arrayRepoOne, $arrayRepoTwo));

        $packages = $repo->getPackages();
        $this->assertCount(2, $packages, "Should get two packages");
        $this->assertEquals("foo", $packages[0]->getName(), "First package should have name of 'foo'");
        $this->assertEquals("1", $packages[0]->getPrettyVersion(), "First package should have pretty version of '1'");
        $this->assertEquals("bar", $packages[1]->getName(), "Second package should have name of 'bar'");
        $this->assertEquals("1", $packages[1]->getPrettyVersion(), "Second package should have pretty version of '1'");
    }

    public function testAddRepository()
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage($this->getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage($this->getPackage('bar', '1'));
        $arrayRepoTwo->addPackage($this->getPackage('bar', '2'));
        $arrayRepoTwo->addPackage($this->getPackage('bar', '3'));

        $repo = new CompositeRepository(array($arrayRepoOne));
        $this->assertCount(1, $repo, "Composite repository should have just one package before addRepository() is called");
        $repo->addRepository($arrayRepoTwo);
        $this->assertCount(4, $repo, "Composite repository should have four packages after addRepository() is called");
    }

    public function testCount()
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage($this->getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage($this->getPackage('bar', '1'));

        $repo = new CompositeRepository(array($arrayRepoOne, $arrayRepoTwo));

        $this->assertEquals(2, count($repo), "Should return '2' for count(\$repo)");
    }

    /**
     * @dataProvider provideMethodCalls
     */
    public function testNoRepositories($method, $args)
    {
        $repo = new CompositeRepository(array());
        $this->assertEquals(array(), call_user_func_array(array($repo, $method), $args));
    }

    public function provideMethodCalls()
    {
        return array(
            array('findPackages', array('foo')),
            array('search', array('foo')),
            array('getPackages', array()),
        );
    }
}
