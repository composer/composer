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

namespace Composer\Test\Grapher;

use Composer\Grapher\RepositoryGraphBuilder;
use Composer\Repository\ArrayRepository;
use Composer\Package\Link;
use Composer\Test\TestCase;

class RepositoryGraphBuilderTest extends TestCase
{
    protected $repo;
    protected $graph;

    public function setUp()
    {
        $this->repo = new ArrayRepository;
        $this->graph = null;
    }

    public function testOneWayDependency()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), 'requires')));

        $this->buildAndVerify('one-way-dependency');
    }

    public function testManyToManyDependencies()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));
        $this->repo->addPackage($packageE = $this->getPackage('E', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), 'requires'),
                                                                 'd' => new Link('A', 'D', $this->getVersionConstraint('<', '1.1'), 'requires'),
                                                                 'e' => new Link('A', 'E', $this->getVersionConstraint('<', '1.1'), 'requires')
            ));
        $packageC->setRequires(array('d' => new Link('C', 'D', $this->getVersionConstraint('<', '1.1'), 'requires')));

        $this->buildAndVerify('many-to-many-dependencies');
    }

    public function testNestedDependencies()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), 'requires')));
        $packageB->setRequires(array('c' => new Link('B', 'C', $this->getVersionConstraint('<', '1.1'), 'requires')));
        $packageC->setRequires(array('d' => new Link('C', 'D', $this->getVersionConstraint('<', '1.1'), 'requires')));

        $this->buildAndVerify('nested-dependencies');
    }

    /**
     * Builds the graph from the repository and
     * asserts the results are equal.
     *
     * @param string $fixture Fixture name to load and verify against
     */
    protected function buildAndVerify($fixtureName)
    {
        $expected = json_decode(file_get_contents(__DIR__.'/Fixtures/'.$fixtureName.'.json'), true);

        $builder = new RepositoryGraphBuilder($this->repo);
        $this->graph = $builder->build();
        $this->assertEqualsArrays($expected, $this->graph, "fixture doesn't match result");
    }

    /**
     * Ignore order in arrays, just assert they have the same contents in any order
     */
    protected function assertEqualsArrays($expected, $actual, $message)
    {
        sort($expected);
        sort($actual);

        $this->assertEquals($expected, $actual, $message);
    }
}
