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
        $rule = new Rule(array(123), 'job1', null);

        $this->assertEquals(substr(md5('123'), 0, 5), $rule->getHash());
    }

    public function testSetAndGetId()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->setId(666);

        $this->assertEquals(666, $rule->getId());
    }

    public function testEqualsForRulesWithDifferentHashes()
    {
        $rule = new Rule(array(1, 2), 'job1', null);
        $rule2 = new Rule(array(1, 3), 'job1', null);

        $this->assertFalse($rule->equals($rule2));
    }

    public function testEqualsForRulesWithDifferLiteralsQuantity()
    {
        $rule = new Rule(array(1, 12), 'job1', null);
        $rule2 = new Rule(array(1), 'job1', null);

        $this->assertFalse($rule->equals($rule2));
    }

    public function testEqualsForRulesWithSameLiterals()
    {
        $rule = new Rule(array(1, 12), 'job1', null);
        $rule2 = new Rule(array(1, 12), 'job1', null);

        $this->assertTrue($rule->equals($rule2));
    }

    public function testSetAndGetType()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->setType('someType');

        $this->assertEquals('someType', $rule->getType());
    }

    public function testEnable()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->disable();
        $rule->enable();

        $this->assertTrue($rule->isEnabled());
        $this->assertFalse($rule->isDisabled());
    }

    public function testDisable()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->enable();
        $rule->disable();

        $this->assertTrue($rule->isDisabled());
        $this->assertFalse($rule->isEnabled());
    }

    public function testIsAssertions()
    {
        $rule = new Rule(array(1, 12), 'job1', null);
        $rule2 = new Rule(array(1), 'job1', null);

        $this->assertFalse($rule->isAssertion());
        $this->assertTrue($rule2->isAssertion());
    }

    public function testPrettyString()
    {
        $repo = new ArrayRepository;
        $repo->addPackage($p1 = $this->getPackage('foo', '2.1'));
        $repo->addPackage($p2 = $this->getPackage('baz', '1.1'));
        $this->pool->addRepository($repo);

        $rule = new Rule(array($p1->getId(), -$p2->getId()), 'job1', null);

        $this->assertEquals('(don\'t install baz 1.1|install foo 2.1)', $rule->getPrettyString($this->pool));
    }
}
