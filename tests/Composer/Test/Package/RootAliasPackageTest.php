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

namespace Composer\Test\Package;

use Composer\Package\Link;
use Composer\Package\RootAliasPackage;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use Prophecy\Argument;

class RootAliasPackageTest extends TestCase
{
    public function testUpdateRequires()
    {
        $root = $this->getMockRootPackageInterface();
        $root->setRequires(Argument::type('array'))->shouldBeCalled();

        $alias = new RootAliasPackage($root->reveal(), '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getRequires());
        $links = array(new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_REQUIRE, 'self.version'));
        $alias->setRequires($links);
        $this->assertNotEmpty($alias->getRequires());
    }

    public function testUpdateDevRequires()
    {
        $root = $this->getMockRootPackageInterface();
        $root->setDevRequires(Argument::type('array'))->shouldBeCalled();

        $alias = new RootAliasPackage($root->reveal(), '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getDevRequires());
        $links = array(new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_DEV_REQUIRE, 'self.version'));
        $alias->setDevRequires($links);
        $this->assertNotEmpty($alias->getDevRequires());
    }

    public function testUpdateConflicts()
    {
        $root = $this->getMockRootPackageInterface();
        $root->setConflicts(Argument::type('array'))->shouldBeCalled();

        $alias = new RootAliasPackage($root->reveal(), '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getConflicts());
        $links = array(new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_CONFLICT, 'self.version'));
        $alias->setConflicts($links);
        $this->assertNotEmpty($alias->getConflicts());
    }

    public function testUpdateProvides()
    {
        $root = $this->getMockRootPackageInterface();
        $root->setProvides(Argument::type('array'))->shouldBeCalled();

        $alias = new RootAliasPackage($root->reveal(), '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getProvides());
        $links = array(new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_PROVIDE, 'self.version'));
        $alias->setProvides($links);
        $this->assertNotEmpty($alias->getProvides());
    }

    public function testUpdateReplaces()
    {
        $root = $this->getMockRootPackageInterface();
        $root->setReplaces(Argument::type('array'))->shouldBeCalled();

        $alias = new RootAliasPackage($root->reveal(), '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getReplaces());
        $links = array(new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_REPLACE, 'self.version'));
        $alias->setReplaces($links);
        $this->assertNotEmpty($alias->getReplaces());
    }

    /**
     * @return \Prophecy\Prophecy\ObjectProphecy<\Composer\Package\RootPackage>
     */
    protected function getMockRootPackageInterface()
    {
        $root = $this->prophesize('Composer\\Package\\RootPackage');
        $root->getName()->willReturn('something/something')->shouldBeCalled();
        $root->getRequires()->willReturn(array())->shouldBeCalled();
        $root->getDevRequires()->willReturn(array())->shouldBeCalled();
        $root->getConflicts()->willReturn(array())->shouldBeCalled();
        $root->getProvides()->willReturn(array())->shouldBeCalled();
        $root->getReplaces()->willReturn(array())->shouldBeCalled();

        return $root;
    }
}
