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
use Composer\DependencyResolver\RuleSetIterator;
use Composer\DependencyResolver\Pool;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class RuleSetIteratorTest extends TestCase
{
    /** @var array<RuleSet::TYPE_*, Rule[]> */
    protected $rules;
    /** @var Pool */
    protected $pool;

    protected function setUp(): void
    {
        $this->pool = new Pool();

        $this->rules = [
            RuleSet::TYPE_REQUEST => [
                new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]),
                new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => '', 'constraint' => new MatchAllConstraint]),
            ],
            RuleSet::TYPE_LEARNED => [
                new GenericRule([], Rule::RULE_LEARNED, 1),
            ],
            RuleSet::TYPE_PACKAGE => [],
        ];
    }

    public function testForeach(): void
    {
        $ruleSetIterator = new RuleSetIterator($this->rules);

        $result = [];
        foreach ($ruleSetIterator as $rule) {
            $result[] = $rule;
        }

        $expected = [
            $this->rules[RuleSet::TYPE_REQUEST][0],
            $this->rules[RuleSet::TYPE_REQUEST][1],
            $this->rules[RuleSet::TYPE_LEARNED][0],
        ];

        $this->assertEquals($expected, $result);
    }

    public function testKeys(): void
    {
        $ruleSetIterator = new RuleSetIterator($this->rules);

        $result = [];
        foreach ($ruleSetIterator as $key => $rule) {
            $result[] = $key;
        }

        $expected = [
            RuleSet::TYPE_REQUEST,
            RuleSet::TYPE_REQUEST,
            RuleSet::TYPE_LEARNED,
        ];

        $this->assertEquals($expected, $result);
    }
}
