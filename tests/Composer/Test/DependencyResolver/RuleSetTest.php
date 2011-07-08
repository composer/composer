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

class RuleSetTest extends \PHPUnit_Framework_TestCase
{
    public function testAdd()
    {
        $rules = array(
            RuleSet::TYPE_PACKAGE => array(),
            RuleSet::TYPE_JOB => array(
                new Rule(array(), 'job1', null),
                new Rule(array(), 'job2', null),
            ),
            RuleSet::TYPE_UPDATE => array(
                new Rule(array(), 'update1', null),
            ),
            RuleSet::TYPE_FEATURE => array(),
            RuleSet::TYPE_LEARNED => array(),
            RuleSet::TYPE_CHOICE => array(),
        );

        $ruleSet = new RuleSet;

        $ruleSet->add($rules[RuleSet::TYPE_JOB][0], RuleSet::TYPE_JOB);
        $ruleSet->add($rules[RuleSet::TYPE_UPDATE][0], RuleSet::TYPE_UPDATE);
        $ruleSet->add($rules[RuleSet::TYPE_JOB][1], RuleSet::TYPE_JOB);

        $this->assertEquals($rules, $ruleSet->getRules());
    }
}
