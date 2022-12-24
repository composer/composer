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

use Composer\Repository\CompositeRepository;
use Composer\Repository\ArrayRepository;
use Composer\Test\TestCase;

class CompositeRepositoryTest extends TestCase
{
    public function testHasPackage(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

        $this->assertTrue($repo->hasPackage(self::getPackage('foo', '1')), "Should have package 'foo/1'");
        $this->assertTrue($repo->hasPackage(self::getPackage('bar', '1')), "Should have package 'bar/1'");

        $this->assertFalse($repo->hasPackage(self::getPackage('foo', '2')), "Should not have package 'foo/2'");
        $this->assertFalse($repo->hasPackage(self::getPackage('bar', '2')), "Should not have package 'bar/2'");
    }

    public function testFindPackage(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

        $this->assertEquals('foo', $repo->findPackage('foo', '1')->getName(), "Should find package 'foo/1' and get name of 'foo'");
        $this->assertEquals('1', $repo->findPackage('foo', '1')->getPrettyVersion(), "Should find package 'foo/1' and get pretty version of '1'");
        $this->assertEquals('bar', $repo->findPackage('bar', '1')->getName(), "Should find package 'bar/1' and get name of 'bar'");
        $this->assertEquals('1', $repo->findPackage('bar', '1')->getPrettyVersion(), "Should find package 'bar/1' and get pretty version of '1'");
        $this->assertNull($repo->findPackage('foo', '2'), "Should not find package 'foo/2'");
    }

    public function testFindPackages(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));
        $arrayRepoOne->addPackage(self::getPackage('foo', '2'));
        $arrayRepoOne->addPackage(self::getPackage('bat', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));
        $arrayRepoTwo->addPackage(self::getPackage('bar', '2'));
        $arrayRepoTwo->addPackage(self::getPackage('foo', '3'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

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

    public function testGetPackages(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

        $packages = $repo->getPackages();
        $this->assertCount(2, $packages, "Should get two packages");
        $this->assertEquals("foo", $packages[0]->getName(), "First package should have name of 'foo'");
        $this->assertEquals("1", $packages[0]->getPrettyVersion(), "First package should have pretty version of '1'");
        $this->assertEquals("bar", $packages[1]->getName(), "Second package should have name of 'bar'");
        $this->assertEquals("1", $packages[1]->getPrettyVersion(), "Second package should have pretty version of '1'");
    }

    public function testAddRepository(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));
        $arrayRepoTwo->addPackage(self::getPackage('bar', '2'));
        $arrayRepoTwo->addPackage(self::getPackage('bar', '3'));

        $repo = new CompositeRepository([$arrayRepoOne]);
        $this->assertCount(1, $repo, "Composite repository should have just one package before addRepository() is called");
        $repo->addRepository($arrayRepoTwo);
        $this->assertCount(4, $repo, "Composite repository should have four packages after addRepository() is called");
    }

    public function testCount(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

        $this->assertCount(2, $repo, "Should return '2' for count(\$repo)");
    }

    /**
     * @dataProvider provideMethodCalls
     *
     * @param mixed[] $args
     */
    public function testNoRepositories(string $method, array $args): void
    {
        $repo = new CompositeRepository([]);
        $this->assertEquals([], call_user_func_array([$repo, $method], $args));
    }

    public static function provideMethodCalls(): array
    {
        return [
            ['findPackages', ['foo']],
            ['search', ['foo']],
            ['getPackages', []],
        ];
    }
}
