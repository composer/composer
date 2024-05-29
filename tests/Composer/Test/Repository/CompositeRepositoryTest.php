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

        self::assertTrue($repo->hasPackage(self::getPackage('foo', '1')), "Should have package 'foo/1'");
        self::assertTrue($repo->hasPackage(self::getPackage('bar', '1')), "Should have package 'bar/1'");

        self::assertFalse($repo->hasPackage(self::getPackage('foo', '2')), "Should not have package 'foo/2'");
        self::assertFalse($repo->hasPackage(self::getPackage('bar', '2')), "Should not have package 'bar/2'");
    }

    public function testFindPackage(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

        self::assertEquals('foo', $repo->findPackage('foo', '1')->getName(), "Should find package 'foo/1' and get name of 'foo'");
        self::assertEquals('1', $repo->findPackage('foo', '1')->getPrettyVersion(), "Should find package 'foo/1' and get pretty version of '1'");
        self::assertEquals('bar', $repo->findPackage('bar', '1')->getName(), "Should find package 'bar/1' and get name of 'bar'");
        self::assertEquals('1', $repo->findPackage('bar', '1')->getPrettyVersion(), "Should find package 'bar/1' and get pretty version of '1'");
        self::assertNull($repo->findPackage('foo', '2'), "Should not find package 'foo/2'");
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
        self::assertCount(1, $bats, "Should find one instance of 'bats' (defined in just one repository)");
        self::assertEquals('bat', $bats[0]->getName(), "Should find packages named 'bat'");

        $bars = $repo->findPackages('bar');
        self::assertCount(2, $bars, "Should find two instances of 'bar' (both defined in the same repository)");
        self::assertEquals('bar', $bars[0]->getName(), "Should find packages named 'bar'");

        $foos = $repo->findPackages('foo');
        self::assertCount(3, $foos, "Should find three instances of 'foo' (two defined in one repository, the third in the other)");
        self::assertEquals('foo', $foos[0]->getName(), "Should find packages named 'foo'");
    }

    public function testGetPackages(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

        $packages = $repo->getPackages();
        self::assertCount(2, $packages, "Should get two packages");
        self::assertEquals("foo", $packages[0]->getName(), "First package should have name of 'foo'");
        self::assertEquals("1", $packages[0]->getPrettyVersion(), "First package should have pretty version of '1'");
        self::assertEquals("bar", $packages[1]->getName(), "Second package should have name of 'bar'");
        self::assertEquals("1", $packages[1]->getPrettyVersion(), "Second package should have pretty version of '1'");
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
        self::assertCount(1, $repo, "Composite repository should have just one package before addRepository() is called");
        $repo->addRepository($arrayRepoTwo);
        self::assertCount(4, $repo, "Composite repository should have four packages after addRepository() is called");
    }

    public function testCount(): void
    {
        $arrayRepoOne = new ArrayRepository;
        $arrayRepoOne->addPackage(self::getPackage('foo', '1'));

        $arrayRepoTwo = new ArrayRepository;
        $arrayRepoTwo->addPackage(self::getPackage('bar', '1'));

        $repo = new CompositeRepository([$arrayRepoOne, $arrayRepoTwo]);

        self::assertCount(2, $repo, "Should return '2' for count(\$repo)");
    }

    /**
     * @dataProvider provideMethodCalls
     *
     * @param mixed[] $args
     */
    public function testNoRepositories(string $method, array $args): void
    {
        $repo = new CompositeRepository([]);
        self::assertEquals([], call_user_func_array([$repo, $method], $args));
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
