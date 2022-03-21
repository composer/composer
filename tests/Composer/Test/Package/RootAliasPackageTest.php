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

namespace Composer\Test\Package;

use Composer\Package\Link;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class RootAliasPackageTest extends TestCase
{
    public function testUpdateRequires(): void
    {
        $links = [new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_REQUIRE, 'self.version')];

        $root = $this->getMockRootPackage();
        $root->expects($this->once())
            ->method('setRequires')
            ->with($this->equalTo($links));

        $alias = new RootAliasPackage($root, '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getRequires());
        $alias->setRequires($links);
        $this->assertNotEmpty($alias->getRequires());
    }

    public function testUpdateDevRequires(): void
    {
        $links = [new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_DEV_REQUIRE, 'self.version')];

        $root = $this->getMockRootPackage();
        $root->expects($this->once())
            ->method('setDevRequires')
            ->with($this->equalTo($links));

        $alias = new RootAliasPackage($root, '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getDevRequires());
        $alias->setDevRequires($links);
        $this->assertNotEmpty($alias->getDevRequires());
    }

    public function testUpdateConflicts(): void
    {
        $links = [new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_CONFLICT, 'self.version')];

        $root = $this->getMockRootPackage();
        $root->expects($this->once())
            ->method('setConflicts')
            ->with($this->equalTo($links));

        $alias = new RootAliasPackage($root, '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getConflicts());
        $alias->setConflicts($links);
        $this->assertNotEmpty($alias->getConflicts());
    }

    public function testUpdateProvides(): void
    {
        $links = [new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_PROVIDE, 'self.version')];

        $root = $this->getMockRootPackage();
        $root->expects($this->once())
            ->method('setProvides')
            ->with($this->equalTo($links));

        $alias = new RootAliasPackage($root, '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getProvides());
        $alias->setProvides($links);
        $this->assertNotEmpty($alias->getProvides());
    }

    public function testUpdateReplaces(): void
    {
        $links = [new Link('a', 'b', new MatchAllConstraint(), Link::TYPE_REPLACE, 'self.version')];

        $root = $this->getMockRootPackage();
        $root->expects($this->once())
            ->method('setReplaces')
            ->with($this->equalTo($links));

        $alias = new RootAliasPackage($root, '1.0', '1.0.0.0');
        $this->assertEmpty($alias->getReplaces());
        $alias->setReplaces($links);
        $this->assertNotEmpty($alias->getReplaces());
    }

    /**
     * @return RootPackage&MockObject
     */
    protected function getMockRootPackage()
    {
        $root = $this->getMockBuilder(RootPackage::class)->disableOriginalConstructor()->getMock();
        $root->expects($this->atLeastOnce())
            ->method('getName')
            ->willReturn('something/something');
        $root->expects($this->atLeastOnce())
            ->method('getRequires')
            ->willReturn([]);
        $root->expects($this->atLeastOnce())
            ->method('getDevRequires')
            ->willReturn([]);
        $root->expects($this->atLeastOnce())
            ->method('getConflicts')
            ->willReturn([]);
        $root->expects($this->atLeastOnce())
            ->method('getProvides')
            ->willReturn([]);
        $root->expects($this->atLeastOnce())
            ->method('getReplaces')
            ->willReturn([]);

        return $root;
    }
}
