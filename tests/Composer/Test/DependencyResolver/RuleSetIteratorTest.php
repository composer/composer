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
use Composer\DependencyResolver\RuleSetIterator;
use Composer\DependencyResolver\Pool;
use Composer\Test\TestCase;

class RuleSetIteratorTest extends TestCase
{
    protected $rules;
    protected $pool;

    protected function setUp()
    {
        $this->pool = new Pool();

        $this->rules = array(
            RuleSet::TYPE_REQUEST => array(
                new GenericRule(array(), Rule::RULE_ROOT_REQUIRE, null),
                new GenericRule(array(), Rule::RULE_ROOT_REQUIRE, null),
            ),
            RuleSet::TYPE_LEARNED => array(
                new GenericRule(array(), Rule::RULE_LEARNED, null),
            ),
            RuleSet::TYPE_PACKAGE => array(),
        );
    }

    public function testForeach()
    {
        $ruleSetIterator = new RuleSetIterator($this->rules);

        $result = array();
        foreach ($ruleSetIterator as $rule) {
            $result[] = $rule;
        }

        $expected = array(
            $this->rules[RuleSet::TYPE_REQUEST][0],
            $this->rules[RuleSet::TYPE_REQUEST][1],
            $this->rules[RuleSet::TYPE_LEARNED][0],
        );

        $this->assertEquals($expected, $result);
    }

    public function testKeys()
    {
        $ruleSetIterator = new RuleSetIterator($this->rules);

        $result = array();
        foreach ($ruleSetIterator as $key => $rule) {
            $result[] = $key;
        }

        $expected = array(
            RuleSet::TYPE_REQUEST,
            RuleSet::TYPE_REQUEST,
            RuleSet::TYPE_LEARNED,
        );

        $this->assertEquals($expected, $result);
    }
}
