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

class RuleTest extends TestCase
{
    protected $pool;

    public function setUp()
    {
        $this->pool = new Pool;
    }

    public function testGetHash()
    {
        $rule = new Rule(array(123), Rule::RULE_JOB_INSTALL, null);

        $hash = unpack('ihash', md5('123', true));
        $this->assertEquals($hash['hash'], $rule->getHash());
    }

    public function testEqualsForRulesWithDifferentHashes()
    {
        $rule = new Rule(array(1, 2), Rule::RULE_JOB_INSTALL, null);
        $rule2 = new Rule(array(1, 3), Rule::RULE_JOB_INSTALL, null);

        $this->assertFalse($rule->equals($rule2));
    }

    public function testEqualsForRulesWithDifferLiteralsQuantity()
    {
        $rule = new Rule(array(1, 12), Rule::RULE_JOB_INSTALL, null);
        $rule2 = new Rule(array(1), Rule::RULE_JOB_INSTALL, null);

        $this->assertFalse($rule->equals($rule2));
    }

    public function testEqualsForRulesWithSameLiterals()
    {
        $rule = new Rule(array(1, 12), Rule::RULE_JOB_INSTALL, null);
        $rule2 = new Rule(array(1, 12), Rule::RULE_JOB_INSTALL, null);

        $this->assertTrue($rule->equals($rule2));
    }

    public function testSetAndGetType()
    {
        $rule = new Rule(array(), Rule::RULE_JOB_INSTALL, null);
        $rule->setType(RuleSet::TYPE_JOB);

        $this->assertEquals(RuleSet::TYPE_JOB, $rule->getType());
    }

    public function testEnable()
    {
        $rule = new Rule(array(), Rule::RULE_JOB_INSTALL, null);
        $rule->disable();
        $rule->enable();

        $this->assertTrue($rule->isEnabled());
        $this->assertFalse($rule->isDisabled());
    }

    public function testDisable()
    {
        $rule = new Rule(array(), Rule::RULE_JOB_INSTALL, null);
        $rule->enable();
        $rule->disable();

        $this->assertTrue($rule->isDisabled());
        $this->assertFalse($rule->isEnabled());
    }

    public function testIsAssertions()
    {
        $rule = new Rule(array(1, 12), Rule::RULE_JOB_INSTALL, null);
        $rule2 = new Rule(array(1), Rule::RULE_JOB_INSTALL, null);

        $this->assertFalse($rule->isAssertion());
        $this->assertTrue($rule2->isAssertion());
    }

    public function testPrettyString()
    {
        $repo = new ArrayRepository;
        $repo->addPackage($p1 = $this->getPackage('foo', '2.1'));
        $repo->addPackage($p2 = $this->getPackage('baz', '1.1'));
        $this->pool->addRepository($repo);

        $rule = new Rule(array($p1->getId(), -$p2->getId()), Rule::RULE_JOB_INSTALL, null);

        $this->assertEquals('Install command rule (don\'t install baz 1.1|install foo 2.1)', $rule->getPrettyString($this->pool));
    }
}
