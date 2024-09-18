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

use Composer\Test\TestCase;
use Composer\Repository\FilterRepository;
use Composer\Repository\ArrayRepository;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Package\BasePackage;

class FilterRepositoryTest extends TestCase
{
    /**
     * @var ArrayRepository
     */
    private $arrayRepo;

    public function setUp(): void
    {
        $this->arrayRepo = new ArrayRepository();
        $this->arrayRepo->addPackage(self::getPackage('foo/aaa', '1.0.0'));
        $this->arrayRepo->addPackage(self::getPackage('foo/bbb', '1.0.0'));
        $this->arrayRepo->addPackage(self::getPackage('bar/xxx', '1.0.0'));
        $this->arrayRepo->addPackage(self::getPackage('baz/yyy', '1.0.0'));
    }

    /**
     * @dataProvider provideRepoMatchingTestCases
     *
     * @param string[]                                                               $expected
     * @param array{only?: array<string>, exclude?: array<string>, canonical?: bool} $config
     */
    public function testRepoMatching(array $expected, $config): void
    {
        $repo = new FilterRepository($this->arrayRepo, $config);
        $packages = $repo->getPackages();

        self::assertSame($expected, array_map(static function ($p): string {
            return $p->getName();
        }, $packages));
    }

    public static function provideRepoMatchingTestCases(): array
    {
        return [
            [['foo/aaa', 'foo/bbb'], ['only' => ['foo/*']]],
            [['foo/aaa', 'baz/yyy'], ['only' => ['foo/aaa', 'baz/yyy']]],
            [['bar/xxx'], ['exclude' => ['foo/*', 'baz/yyy']]],
            // make sure sub-patterns are not matched without wildcard
            [['foo/aaa', 'foo/bbb', 'bar/xxx', 'baz/yyy'], ['exclude' => ['foo/aa', 'az/yyy']]],
            [[], ['only' => ['foo/aa', 'az/yyy']]],
            // empty "only" means no packages allowed
            [[], ['only' => []]],
            // absent "only" means all packages allowed
            [['foo/aaa', 'foo/bbb', 'bar/xxx', 'baz/yyy'], []],
            // empty or absent "exclude" have the same effect: none
            [['foo/aaa', 'foo/bbb', 'bar/xxx', 'baz/yyy'], ['exclude' => []]],
            [['foo/aaa', 'foo/bbb', 'bar/xxx', 'baz/yyy'], []],
        ];
    }

    public function testBothFiltersDisallowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FilterRepository($this->arrayRepo, ['only' => [], 'exclude' => []]);
    }

    public function testSecurityAdvisoriesDisabledInChild(): void
    {
        $repo = new FilterRepository($this->arrayRepo, ['only' => ['foo/*']]);

        self::assertFalse($repo->hasSecurityAdvisories());
        self::assertSame(['namesFound' => [], 'advisories' => []], $repo->getSecurityAdvisories(['foo/aaa' => new MatchAllConstraint()], true));
    }

    public function testCanonicalDefaultTrue(): void
    {
        $repo = new FilterRepository($this->arrayRepo, []);
        $result = $repo->loadPackages(['foo/aaa' => new MatchAllConstraint], BasePackage::STABILITIES, []);
        self::assertCount(1, $result['packages']);
        self::assertCount(1, $result['namesFound']);
    }

    public function testNonCanonical(): void
    {
        $repo = new FilterRepository($this->arrayRepo, ['canonical' => false]);
        $result = $repo->loadPackages(['foo/aaa' => new MatchAllConstraint], BasePackage::STABILITIES, []);
        self::assertCount(1, $result['packages']);
        self::assertCount(0, $result['namesFound']);
    }
}
