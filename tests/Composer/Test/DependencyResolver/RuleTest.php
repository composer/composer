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
use Composer\DependencyResolver\Literal;
use Composer\Test\TestCase;

class RuleTest extends TestCase
{
    public function testGetHash()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->ruleHash = '123';

        $this->assertEquals('123', $rule->getHash());
    }

    public function testSetAndGetId()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->setId(666);

        $this->assertEquals(666, $rule->getId());
    }

    public function testEqualsForRulesWithDifferentHashes()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->ruleHash = '123';

        $rule2 = new Rule(array(), 'job1', null);
        $rule2->ruleHash = '321';

        $this->assertFalse($rule->equals($rule2));
    }

    public function testEqualsForRulesWithDifferentLiterals()
    {
        $literal = $this->getLiteralMock();
        $literal->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(1));
        $rule = new Rule(array($literal), 'job1', null);
        $rule->ruleHash = '123';

        $literal = $this->getLiteralMock();
        $literal->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(12));
        $rule2 = new Rule(array($literal), 'job1', null);
        $rule2->ruleHash = '123';

        $this->assertFalse($rule->equals($rule2));
    }

    public function testEqualsForRulesWithDifferLiteralsQuantity()
    {
        $literal = $this->getLiteralMock();
        $literal->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(1));
        $literal2 = $this->getLiteralMock();
        $literal2->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(12));

        $rule = new Rule(array($literal, $literal2), 'job1', null);
        $rule->ruleHash = '123';
        $rule2 = new Rule(array($literal), 'job1', null);
        $rule2->ruleHash = '123';

        $this->assertFalse($rule->equals($rule2));
    }

    public function testEqualsForRulesWithThisSameLiterals()
    {
        $literal = $this->getLiteralMock();
        $literal->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(1));
        $literal2 = $this->getLiteralMock();
        $literal2->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(12));

        $rule = new Rule(array($literal, $literal2), 'job1', null);
        $rule2 = new Rule(array($literal, $literal2), 'job1', null);

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

    public function testSetWeak()
    {
        $rule = new Rule(array(), 'job1', null);
        $rule->setWeak(true);

        $rule2 = new Rule(array(), 'job1', null);
        $rule2->setWeak(false);

        $this->assertTrue($rule->isWeak());
        $this->assertFalse($rule2->isWeak());
    }

    public function testIsAssertions()
    {
        $literal = $this->getLiteralMock();
        $literal2 = $this->getLiteralMock();
        $rule = new Rule(array($literal, $literal2), 'job1', null);
        $rule2 = new Rule(array($literal), 'job1', null);

        $this->assertFalse($rule->isAssertion());
        $this->assertTrue($rule2->isAssertion());
    }

    public function testToString()
    {
        $literal = new Literal($this->getPackage('foo', '2.1'), true);
        $literal2 = new Literal($this->getPackage('baz', '1.1'), false);

        $rule = new Rule(array($literal, $literal2), 'job1', null);

        $this->assertEquals('(-baz-1.1.0.0|+foo-2.1.0.0)', $rule->__toString());
    }

    private function getLiteralMock()
    {
        return $this->getMockBuilder('Composer\DependencyResolver\Literal')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
