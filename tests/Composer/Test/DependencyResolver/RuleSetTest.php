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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\GenericRule;
use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\RuleSet;
use Composer\DependencyResolver\Pool;
use Composer\Test\TestCase;

class RuleSetTest extends TestCase
{
    public function testAdd()
    {
        $rules = array(
            RuleSet::TYPE_PACKAGE => array(),
            RuleSet::TYPE_REQUEST => array(
                new GenericRule(array(1), Rule::RULE_ROOT_REQUIRE, null),
                new GenericRule(array(2), Rule::RULE_ROOT_REQUIRE, null),
            ),
            RuleSet::TYPE_LEARNED => array(
                new GenericRule(array(), Rule::RULE_LEARNED, null),
            ),
        );

        $ruleSet = new RuleSet;

        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][0], RuleSet::TYPE_REQUEST);
        $ruleSet->add($rules[RuleSet::TYPE_LEARNED][0], RuleSet::TYPE_LEARNED);
        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][1], RuleSet::TYPE_REQUEST);

        $this->assertEquals($rules, $ruleSet->getRules());
    }

    public function testAddIgnoresDuplicates()
    {
        $rules = array(
            RuleSet::TYPE_REQUEST => array(
                new GenericRule(array(), Rule::RULE_ROOT_REQUIRE, null),
                new GenericRule(array(), Rule::RULE_ROOT_REQUIRE, null),
                new GenericRule(array(), Rule::RULE_ROOT_REQUIRE, null),
            ),
        );

        $ruleSet = new RuleSet;

        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][0], RuleSet::TYPE_REQUEST);
        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][1], RuleSet::TYPE_REQUEST);
        $ruleSet->add($rules[RuleSet::TYPE_REQUEST][2], RuleSet::TYPE_REQUEST);

        $this->assertCount(1, $ruleSet->getIteratorFor(array(RuleSet::TYPE_REQUEST)));
    }

    public function testAddWhenTypeIsNotRecognized()
    {
        $ruleSet = new RuleSet;

        $this->setExpectedException('OutOfBoundsException');
        $ruleSet->add(new GenericRule(array(), Rule::RULE_ROOT_REQUIRE, null), 7);
    }

    public function testCount()
    {
        $ruleSet = new RuleSet;

        $ruleSet->add(new GenericRule(array(1), Rule::RULE_ROOT_REQUIRE, null), RuleSet::TYPE_REQUEST);
        $ruleSet->add(new GenericRule(array(2), Rule::RULE_ROOT_REQUIRE, null), RuleSet::TYPE_REQUEST);

        $this->assertEquals(2, $ruleSet->count());
    }

    public function testRuleById()
    {
        $ruleSet = new RuleSet;

        $rule = new GenericRule(array(), Rule::RULE_ROOT_REQUIRE, null);
        $ruleSet->add($rule, RuleSet::TYPE_REQUEST);

        $this->assertSame($rule, $ruleSet->ruleById[0]);
    }

    public function testGetIterator()
    {
        $ruleSet = new RuleSet;

        $rule1 = new GenericRule(array(1), Rule::RULE_ROOT_REQUIRE, null);
        $rule2 = new GenericRule(array(2), Rule::RULE_ROOT_REQUIRE, null);
        $ruleSet->add($rule1, RuleSet::TYPE_REQUEST);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIterator();

        $this->assertSame($rule1, $iterator->current());
        $iterator->next();
        $this->assertSame($rule2, $iterator->current());
    }

    public function testGetIteratorFor()
    {
        $ruleSet = new RuleSet;
        $rule1 = new GenericRule(array(1), Rule::RULE_ROOT_REQUIRE, null);
        $rule2 = new GenericRule(array(2), Rule::RULE_ROOT_REQUIRE, null);

        $ruleSet->add($rule1, RuleSet::TYPE_REQUEST);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIteratorFor(RuleSet::TYPE_LEARNED);

        $this->assertSame($rule2, $iterator->current());
    }

    public function testGetIteratorWithout()
    {
        $ruleSet = new RuleSet;
        $rule1 = new GenericRule(array(1), Rule::RULE_ROOT_REQUIRE, null);
        $rule2 = new GenericRule(array(2), Rule::RULE_ROOT_REQUIRE, null);

        $ruleSet->add($rule1, RuleSet::TYPE_REQUEST);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIteratorWithout(RuleSet::TYPE_REQUEST);

        $this->assertSame($rule2, $iterator->current());
    }

    public function testPrettyString()
    {
        $pool = new Pool(array(
            $p = $this->getPackage('foo', '2.1'),
        ));

        $repositorySetMock = $this->getMockBuilder('Composer\Repository\RepositorySet')->disableOriginalConstructor()->getMock();
        $requestMock = $this->getMockBuilder('Composer\DependencyResolver\Request')->disableOriginalConstructor()->getMock();

        $ruleSet = new RuleSet;
        $literal = $p->getId();
        $rule = new GenericRule(array($literal), Rule::RULE_ROOT_REQUIRE, array('packageName' => 'foo/bar', 'constraint' => null));

        $ruleSet->add($rule, RuleSet::TYPE_REQUEST);

        $this->assertStringContainsString('REQUEST : No package found to satisfy root composer.json require foo/bar', $ruleSet->getPrettyString($repositorySetMock, $requestMock, $pool));
    }
}
