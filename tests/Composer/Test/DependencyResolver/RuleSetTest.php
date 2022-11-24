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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\GenericRule;
use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\RuleSet;
use Composer\DependencyResolver\Pool;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MatchNoneConstraint;
use Composer\Test\TestCase;

class RuleSetTest extends TestCase
{
    public function testAdd(): void
    {
        $rules = [
            RuleSet::TYPE_PACKAGE => [],
            RuleSet::TYPE_REQUEST => [
                new GenericRule([1], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]),
                new GenericRule([2], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]),
            ],
            RuleSet::TYPE_LEARNED => [
                new GenericRule([], Rule::RULE_LEARNED, 1),
            ],
        ];

        $ruleSet = new RuleSet;

        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][0], RuleSet::TYPE_REQUEST);
        $ruleSet->add($rules[RuleSet::TYPE_LEARNED][0], RuleSet::TYPE_LEARNED);
        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][1], RuleSet::TYPE_REQUEST);

        $this->assertEquals($rules, $ruleSet->getRules());
    }

    public function testAddIgnoresDuplicates(): void
    {
        $rules = [
            RuleSet::TYPE_REQUEST => [
                new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]),
                new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]),
                new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]),
            ],
        ];

        $ruleSet = new RuleSet;

        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][0], RuleSet::TYPE_REQUEST);
        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][1], RuleSet::TYPE_REQUEST);
        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][2], RuleSet::TYPE_REQUEST);

        $this->assertCount(1, $ruleSet->getIteratorFor([RuleSet::TYPE_REQUEST]));
    }

    public function testAddWhenTypeIsNotRecognized(): void
    {
        $ruleSet = new RuleSet;

        self::expectException('OutOfBoundsException');
        // @phpstan-ignore-next-line
        $ruleSet->add(new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]), 7);
    }

    public function testCount(): void
    {
        $ruleSet = new RuleSet;

        $ruleSet->add(new GenericRule([1], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]), RuleSet::TYPE_REQUEST);
        $ruleSet->add(new GenericRule([2], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]), RuleSet::TYPE_REQUEST);

        $this->assertEquals(2, $ruleSet->count());
    }

    public function testRuleById(): void
    {
        $ruleSet = new RuleSet;

        $rule = new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]);
        $ruleSet->add($rule, RuleSet::TYPE_REQUEST);

        $this->assertSame($rule, $ruleSet->ruleById[0]);
    }

    public function testGetIterator(): void
    {
        $ruleSet = new RuleSet;

        $rule1 = new GenericRule([1], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]);
        $rule2 = new GenericRule([2], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]);
        $ruleSet->add($rule1, RuleSet::TYPE_REQUEST);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIterator();

        $this->assertSame($rule1, $iterator->current());
        $iterator->next();
        $this->assertSame($rule2, $iterator->current());
    }

    public function testGetIteratorFor(): void
    {
        $ruleSet = new RuleSet;
        $rule1 = new GenericRule([1], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]);
        $rule2 = new GenericRule([2], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]);

        $ruleSet->add($rule1, RuleSet::TYPE_REQUEST);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIteratorFor(RuleSet::TYPE_LEARNED);

        $this->assertSame($rule2, $iterator->current());
    }

    public function testGetIteratorWithout(): void
    {
        $ruleSet = new RuleSet;
        $rule1 = new GenericRule([1], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]);
        $rule2 = new GenericRule([2], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]);

        $ruleSet->add($rule1, RuleSet::TYPE_REQUEST);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIteratorWithout(RuleSet::TYPE_REQUEST);

        $this->assertSame($rule2, $iterator->current());
    }

    public function testPrettyString(): void
    {
        $pool = new Pool([
            $p = self::getPackage('foo', '2.1'),
        ]);

        $repositorySetMock = $this->getMockBuilder('Composer\Repository\RepositorySet')->disableOriginalConstructor()->getMock();
        $requestMock = $this->getMockBuilder('Composer\DependencyResolver\Request')->disableOriginalConstructor()->getMock();

        $ruleSet = new RuleSet;
        $literal = $p->getId();
        $rule = new GenericRule([$literal], Rule::RULE_ROOT_REQUIRE, ['packageName' => 'foo/bar', 'constraint' => new MatchNoneConstraint]);

        $ruleSet->add($rule, RuleSet::TYPE_REQUEST);

        $this->assertStringContainsString('REQUEST : No package found to satisfy root composer.json require foo/bar', $ruleSet->getPrettyString($repositorySetMock, $requestMock, $pool));
    }
}
