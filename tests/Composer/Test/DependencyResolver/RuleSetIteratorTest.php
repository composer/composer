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
use Composer\DependencyResolver\RuleSetIterator;
use Composer\DependencyResolver\Pool;

class RuleSetIteratorTest extends \PHPUnit_Framework_TestCase
{
    protected $rules;

    protected function setUp()
    {
        $this->pool = new Pool;

        $this->rules = array(
            RuleSet::TYPE_JOB => array(
                new Rule($this->pool, array(), 'job1', null),
                new Rule($this->pool, array(), 'job2', null),
            ),
            RuleSet::TYPE_LEARNED => array(
                new Rule($this->pool, array(), 'update1', null),
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
            $this->rules[RuleSet::TYPE_JOB][0],
            $this->rules[RuleSet::TYPE_JOB][1],
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
            RuleSet::TYPE_JOB,
            RuleSet::TYPE_JOB,
            RuleSet::TYPE_LEARNED,
        );

        $this->assertEquals($expected, $result);
    }
}
