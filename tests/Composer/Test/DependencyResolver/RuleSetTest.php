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

use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\RuleSet;
use Composer\DependencyResolver\Pool;
use Composer\Repository\ArrayRepository;
use Composer\TestCase;

class RuleSetTest extends TestCase
{
    protected $pool;

    public function setUp()
    {
        $this->pool = new Pool;
    }

    public function testAdd()
    {
        $rules = array(
            RuleSet::TYPE_PACKAGE => array(),
            RuleSet::TYPE_JOB => array(
                new Rule(array(), 'job1', null),
                new Rule(array(), 'job2', null),
            ),
            RuleSet::TYPE_LEARNED => array(
                new Rule(array(), 'update1', null),
            ),
        );

        $ruleSet = new RuleSet;

        $ruleSet->add($rules[RuleSet::TYPE_JOB][0], RuleSet::TYPE_JOB);
        $ruleSet->add($rules[RuleSet::TYPE_LEARNED][0], RuleSet::TYPE_LEARNED);
        $ruleSet->add($rules[RuleSet::TYPE_JOB][1], RuleSet::TYPE_JOB);

        $this->assertEquals($rules, $ruleSet->getRules());
    }

    /**
     * @expectedException \OutOfBoundsException
     */
    public function testAddWhenTypeIsNotRecognized()
    {
        $ruleSet = new RuleSet;

        $ruleSet->add(new Rule(array(), 'job1', null), 7);
    }

    public function testCount()
    {
        $ruleSet = new RuleSet;

        $ruleSet->add(new Rule(array(), 'job1', null), RuleSet::TYPE_JOB);
        $ruleSet->add(new Rule(array(), 'job2', null), RuleSet::TYPE_JOB);

        $this->assertEquals(2, $ruleSet->count());
    }

    public function testRuleById()
    {
        $ruleSet = new RuleSet;

        $rule = new Rule(array(), 'job1', null);
        $ruleSet->add($rule, RuleSet::TYPE_JOB);

        $this->assertSame($rule, $ruleSet->ruleById[0]);
    }

    public function testGetIterator()
    {
        $ruleSet = new RuleSet;

        $rule1 = new Rule(array(), 'job1', null);
        $rule2 = new Rule(array(), 'job1', null);
        $ruleSet->add($rule1, RuleSet::TYPE_JOB);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIterator();

        $this->assertSame($rule1, $iterator->current());
        $iterator->next();
        $this->assertSame($rule2, $iterator->current());
    }

    public function testGetIteratorFor()
    {
        $ruleSet = new RuleSet;
        $rule1 = new Rule(array(), 'job1', null);
        $rule2 = new Rule(array(), 'job1', null);

        $ruleSet->add($rule1, RuleSet::TYPE_JOB);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIteratorFor(RuleSet::TYPE_LEARNED);

        $this->assertSame($rule2, $iterator->current());
    }

    public function testGetIteratorWithout()
    {
        $ruleSet = new RuleSet;
        $rule1 = new Rule(array(), 'job1', null);
        $rule2 = new Rule(array(), 'job1', null);

        $ruleSet->add($rule1, RuleSet::TYPE_JOB);
        $ruleSet->add($rule2, RuleSet::TYPE_LEARNED);

        $iterator = $ruleSet->getIteratorWithout(RuleSet::TYPE_JOB);

        $this->assertSame($rule2, $iterator->current());
    }

    public function testContainsEqual()
    {
        $ruleSet = new RuleSet;

        $rule = $this->getRuleMock();
        $rule->expects($this->any())
            ->method('getHash')
            ->will($this->returnValue('rule_1_hash'));
        $rule->expects($this->any())
            ->method('equals')
            ->will($this->returnValue(true));

        $rule2 = $this->getRuleMock();
        $rule2->expects($this->any())
            ->method('getHash')
            ->will($this->returnValue('rule_2_hash'));

        $rule3 = $this->getRuleMock();
        $rule3->expects($this->any())
            ->method('getHash')
            ->will($this->returnValue('rule_1_hash'));
        $rule3->expects($this->any())
            ->method('equal')
            ->will($this->returnValue(false));

        $ruleSet->add($rule, RuleSet::TYPE_LEARNED);

        $this->assertTrue($ruleSet->containsEqual($rule));
        $this->assertFalse($ruleSet->containsEqual($rule2));
        $this->assertFalse($ruleSet->containsEqual($rule3));
    }

    public function testPrettyString()
    {
        $repo = new ArrayRepository;
        $repo->addPackage($p = $this->getPackage('foo', '2.1'));
        $this->pool->addRepository($repo);

        $ruleSet = new RuleSet;
        $literal = $p->getId();
        $rule = new Rule(array($literal), 'job1', null);

        $ruleSet->add($rule, RuleSet::TYPE_JOB);

        $this->assertContains('JOB     : (install foo 2.1)', $ruleSet->getPrettyString($this->pool));
    }

    private function getRuleMock()
    {
        return $this->getMockBuilder('Composer\DependencyResolver\Rule')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
