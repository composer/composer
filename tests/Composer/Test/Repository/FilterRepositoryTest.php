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
        $this->arrayRepo->addPackage($this->getPackage('foo/aaa', '1.0.0'));
        $this->arrayRepo->addPackage($this->getPackage('foo/bbb', '1.0.0'));
        $this->arrayRepo->addPackage($this->getPackage('bar/xxx', '1.0.0'));
        $this->arrayRepo->addPackage($this->getPackage('baz/yyy', '1.0.0'));
    }

    /**
     * @dataProvider provideRepoMatchingTestCases
     *
     * @param string[]                                                               $expected
     * @param array{only?: array<string>, exclude?: array<string>, canonical?: bool} $config
     */
    public function testRepoMatching($expected, $config): void
    {
        $repo = new FilterRepository($this->arrayRepo, $config);
        $packages = $repo->getPackages();

        $this->assertSame($expected, array_map(function ($p): string {
            return $p->getName();
        }, $packages));
    }

    public static function provideRepoMatchingTestCases(): array
    {
        return array(
            array(array('foo/aaa', 'foo/bbb'), array('only' => array('foo/*'))),
            array(array('foo/aaa', 'baz/yyy'), array('only' => array('foo/aaa', 'baz/yyy'))),
            array(array('bar/xxx'), array('exclude' => array('foo/*', 'baz/yyy'))),
            // make sure sub-patterns are not matched without wildcard
            array(array('foo/aaa', 'foo/bbb', 'bar/xxx', 'baz/yyy'), array('exclude' => array('foo/aa', 'az/yyy'))),
            array(array(), array('only' => array('foo/aa', 'az/yyy'))),
        );
    }

    public function testCanonicalDefaultTrue(): void
    {
        $repo = new FilterRepository($this->arrayRepo, array());
        $result = $repo->loadPackages(array('foo/aaa' => new MatchAllConstraint), BasePackage::$stabilities, array());
        $this->assertCount(1, $result['packages']);
        $this->assertCount(1, $result['namesFound']);
    }

    public function testNonCanonical(): void
    {
        $repo = new FilterRepository($this->arrayRepo, array('canonical' => false));
        $result = $repo->loadPackages(array('foo/aaa' => new MatchAllConstraint), BasePackage::$stabilities, array());
        $this->assertCount(1, $result['packages']);
        $this->assertCount(0, $result['namesFound']);
    }
}
