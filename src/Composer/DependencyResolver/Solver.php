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

namespace Composer\DependencyResolver;

use Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Solver
{
    private const BRANCH_LITERALS = 0;
    private const BRANCH_LEVEL = 1;

    /** @var PolicyInterface */
    protected $policy;
    /** @var Pool */
    protected $pool;

    /** @var RuleSet */
    protected $rules;

    /** @var RuleWatchGraph */
    protected $watchGraph;
    /** @var Decisions */
    protected $decisions;
    /** @var BasePackage[] */
    protected $fixedMap;

    /** @var int */
    protected $propagateIndex;
    /** @var array<int, array{array<int, int>, int}> */
    protected $branches = [];
    /** @var Problem[] */
    protected $problems = [];
    /** @var array<Rule[]> */
    protected $learnedPool = [];
    /** @var array<string, int> */
    protected $learnedWhy = [];

    /** @var bool */
    public $testFlagLearnedPositiveLiteral = false;

    /** @var IOInterface */
    protected $io;

    public function __construct(PolicyInterface $policy, Pool $pool, IOInterface $io)
    {
        $this->io = $io;
        $this->policy = $policy;
        $this->pool = $pool;
    }

    public function getRuleSetSize(): int
    {
        return \count($this->rules);
    }

    public function getPool(): Pool
    {
        return $this->pool;
    }

    // aka solver_makeruledecisions

    private function makeAssertionRuleDecisions(): void
    {
        $decisionStart = \count($this->decisions) - 1;

        $rulesCount = \count($this->rules);
        for ($ruleIndex = 0; $ruleIndex < $rulesCount; $ruleIndex++) {
            $rule = $this->rules->ruleById[$ruleIndex];

            if (!$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            $literals = $rule->getLiterals();
            $literal = $literals[0];

            if (!$this->decisions->decided($literal)) {
                $this->decisions->decide($literal, 1, $rule);
                continue;
            }

            if ($this->decisions->satisfy($literal)) {
                continue;
            }

            // found a conflict
            if (RuleSet::TYPE_LEARNED === $rule->getType()) {
                $rule->disable();
                continue;
            }

            $conflict = $this->decisions->decisionRule($literal);

            if (RuleSet::TYPE_PACKAGE === $conflict->getType()) {
                $problem = new Problem();

                $problem->addRule($rule);
                $problem->addRule($conflict);
                $rule->disable();
                $this->problems[] = $problem;
                continue;
            }

            // conflict with another root require/fixed package
            $problem = new Problem();
            $problem->addRule($rule);
            $problem->addRule($conflict);

            // push all of our rules (can only be root require/fixed package rules)
            // asserting this literal on the problem stack
            foreach ($this->rules->getIteratorFor(RuleSet::TYPE_REQUEST) as $assertRule) {
                if ($assertRule->isDisabled() || !$assertRule->isAssertion()) {
                    continue;
                }

                $assertRuleLiterals = $assertRule->getLiterals();
                $assertRuleLiteral = $assertRuleLiterals[0];

                if (abs($literal) !== abs($assertRuleLiteral)) {
                    continue;
                }
                $problem->addRule($assertRule);
                $assertRule->disable();
            }
            $this->problems[] = $problem;

            $this->decisions->resetToOffset($decisionStart);
            $ruleIndex = -1;
        }
    }

    protected function setupFixedMap(Request $request): void
    {
        $this->fixedMap = [];
        foreach ($request->getFixedPackages() as $package) {
            $this->fixedMap[$package->id] = $package;
        }
    }

    protected function checkForRootRequireProblems(Request $request, PlatformRequirementFilterInterface $platformRequirementFilter): void
    {
        foreach ($request->getRequires() as $packageName => $constraint) {
            if ($platformRequirementFilter->isIgnored($packageName)) {
                continue;
            } elseif ($platformRequirementFilter instanceof IgnoreListPlatformRequirementFilter) {
                $constraint = $platformRequirementFilter->filterConstraint($packageName, $constraint);
            }

            if (0 === \count($this->pool->whatProvides($packageName, $constraint))) {
                $problem = new Problem();
                $problem->addRule(new GenericRule([], Rule::RULE_ROOT_REQUIRE, ['packageName' => $packageName, 'constraint' => $constraint]));
                $this->problems[] = $problem;
            }
        }
    }

    public function solve(Request $request, ?PlatformRequirementFilterInterface $platformRequirementFilter = null): LockTransaction
    {
        $platformRequirementFilter = $platformRequirementFilter ?? PlatformRequirementFilterFactory::ignoreNothing();

        $this->setupFixedMap($request);

        $this->io->writeError('Generating rules', true, IOInterface::DEBUG);
        $ruleSetGenerator = new RuleSetGenerator($this->policy, $this->pool);
        $this->rules = $ruleSetGenerator->getRulesFor($request, $platformRequirementFilter);
        unset($ruleSetGenerator);
        $this->checkForRootRequireProblems($request, $platformRequirementFilter);
        $this->decisions = new Decisions($this->pool);
        $this->watchGraph = new RuleWatchGraph;

        foreach ($this->rules as $rule) {
            $this->watchGraph->insert(new RuleWatchNode($rule));
        }

        /* make decisions based on root require/fix assertions */
        $this->makeAssertionRuleDecisions();

        $this->io->writeError('Resolving dependencies through SAT', true, IOInterface::DEBUG);
        $before = microtime(true);
        $this->runSat();
        $this->io->writeError('', true, IOInterface::DEBUG);
        $this->io->writeError(sprintf('Dependency resolution completed in %.3f seconds', microtime(true) - $before), true, IOInterface::VERBOSE);

        if (\count($this->problems) > 0) {
            throw new SolverProblemsException($this->problems, $this->learnedPool);
        }

        return new LockTransaction($this->pool, $request->getPresentMap(), $request->getFixedPackagesMap(), $this->decisions);
    }

    /**
     * Makes a decision and propagates it to all rules.
     *
     * Evaluates each term affected by the decision (linked through watches)
     * If we find unit rules we make new decisions based on them
     *
     * @return Rule|null A rule on conflict, otherwise null.
     */
    protected function propagate(int $level): ?Rule
    {
        while ($this->decisions->validOffset($this->propagateIndex)) {
            $decision = $this->decisions->atOffset($this->propagateIndex);

            $conflict = $this->watchGraph->propagateLiteral(
                $decision[Decisions::DECISION_LITERAL],
                $level,
                $this->decisions
            );

            $this->propagateIndex++;

            if ($conflict !== null) {
                return $conflict;
            }
        }

        return null;
    }

    /**
     * Reverts a decision at the given level.
     */
    private function revert(int $level): void
    {
        while (!$this->decisions->isEmpty()) {
            $literal = $this->decisions->lastLiteral();

            if ($this->decisions->undecided($literal)) {
                break;
            }

            $decisionLevel = $this->decisions->decisionLevel($literal);

            if ($decisionLevel <= $level) {
                break;
            }

            $this->decisions->revertLast();
            $this->propagateIndex = \count($this->decisions);
        }

        while (\count($this->branches) > 0 && $this->branches[\count($this->branches) - 1][self::BRANCH_LEVEL] >= $level) {
            array_pop($this->branches);
        }
    }

    /**
     * setpropagatelearn
     *
     * add free decision (a positive literal) to decision queue
     * increase level and propagate decision
     * return if no conflict.
     *
     * in conflict case, analyze conflict rule, add resulting
     * rule to learnt rule set, make decision from learnt
     * rule (always unit) and re-propagate.
     *
     * returns the new solver level or 0 if unsolvable
     */
    private function setPropagateLearn(int $level, int $literal, Rule $rule): int
    {
        $level++;

        $this->decisions->decide($literal, $level, $rule);

        while (true) {
            $rule = $this->propagate($level);

            if (null === $rule) {
                break;
            }

            if ($level === 1) {
                $this->analyzeUnsolvable($rule);

                return 0;
            }

            // conflict
            [$learnLiteral, $newLevel, $newRule, $why] = $this->analyze($level, $rule);

            if ($newLevel <= 0 || $newLevel >= $level) {
                throw new SolverBugException(
                    "Trying to revert to invalid level ".$newLevel." from level ".$level."."
                );
            }

            $level = $newLevel;

            $this->revert($level);

            $this->rules->add($newRule, RuleSet::TYPE_LEARNED);

            $this->learnedWhy[spl_object_hash($newRule)] = $why;

            $ruleNode = new RuleWatchNode($newRule);
            $ruleNode->watch2OnHighest($this->decisions);
            $this->watchGraph->insert($ruleNode);

            $this->decisions->decide($learnLiteral, $level, $newRule);
        }

        return $level;
    }

    /**
     * @param non-empty-list<int> $decisionQueue
     */
    private function selectAndInstall(int $level, array $decisionQueue, Rule $rule): int
    {
        // choose best package to install from decisionQueue
        $literals = $this->policy->selectPreferredPackages($this->pool, $decisionQueue, $rule->getRequiredPackage());

        $selectedLiteral = array_shift($literals);

        // if there are multiple candidates, then branch
        if (\count($literals) > 0) {
            $this->branches[] = [$literals, $level];
        }

        return $this->setPropagateLearn($level, $selectedLiteral, $rule);
    }

    /**
     * @return array{int, int, GenericRule, int}
     */
    protected function analyze(int $level, Rule $rule): array
    {
        $analyzedRule = $rule;
        $ruleLevel = 1;
        $num = 0;
        $l1num = 0;
        $seen = [];
        $learnedLiteral = null;
        $otherLearnedLiterals = [];

        $decisionId = \count($this->decisions);

        $this->learnedPool[] = [];

        while (true) {
            $this->learnedPool[\count($this->learnedPool) - 1][] = $rule;

            foreach ($rule->getLiterals() as $literal) {
                // multiconflictrule is really a bunch of rules in one, so some may not have finished propagating yet
                if ($rule instanceof MultiConflictRule && !$this->decisions->decided($literal)) {
                    continue;
                }

                // skip the one true literal
                if ($this->decisions->satisfy($literal)) {
                    continue;
                }

                if (isset($seen[abs($literal)])) {
                    continue;
                }
                $seen[abs($literal)] = true;

                $l = $this->decisions->decisionLevel($literal);

                if (1 === $l) {
                    $l1num++;
                } elseif ($level === $l) {
                    $num++;
                } else {
                    // not level1 or conflict level, add to new rule
                    $otherLearnedLiterals[] = $literal;

                    if ($l > $ruleLevel) {
                        $ruleLevel = $l;
                    }
                }
            }
            unset($literal);

            $l1retry = true;
            while ($l1retry) {
                $l1retry = false;

                if (0 === $num && 0 === --$l1num) {
                    // all level 1 literals done
                    break 2;
                }

                while (true) {
                    if ($decisionId <= 0) {
                        throw new SolverBugException(
                            "Reached invalid decision id $decisionId while looking through $rule for a literal present in the analyzed rule $analyzedRule."
                        );
                    }

                    $decisionId--;

                    $decision = $this->decisions->atOffset($decisionId);
                    $literal = $decision[Decisions::DECISION_LITERAL];

                    if (isset($seen[abs($literal)])) {
                        break;
                    }
                }

                unset($seen[abs($literal)]);

                if (0 !== $num && 0 === --$num) {
                    if ($literal < 0) {
                        $this->testFlagLearnedPositiveLiteral = true;
                    }
                    $learnedLiteral = -$literal;

                    if (0 === $l1num) {
                        break 2;
                    }

                    foreach ($otherLearnedLiterals as $otherLiteral) {
                        unset($seen[abs($otherLiteral)]);
                    }
                    // only level 1 marks left
                    $l1num++;
                    $l1retry = true;
                } else {
                    $decision = $this->decisions->atOffset($decisionId);
                    $rule = $decision[Decisions::DECISION_REASON];

                    if ($rule instanceof MultiConflictRule) {
                        // there is only ever exactly one positive decision in a MultiConflictRule
                        foreach ($rule->getLiterals() as $ruleLiteral) {
                            if (!isset($seen[abs($ruleLiteral)]) && $this->decisions->satisfy(-$ruleLiteral)) {
                                $this->learnedPool[\count($this->learnedPool) - 1][] = $rule;
                                $l = $this->decisions->decisionLevel($ruleLiteral);
                                if (1 === $l) {
                                    $l1num++;
                                } elseif ($level === $l) {
                                    $num++;
                                } else {
                                    // not level1 or conflict level, add to new rule
                                    $otherLearnedLiterals[] = $ruleLiteral;

                                    if ($l > $ruleLevel) {
                                        $ruleLevel = $l;
                                    }
                                }
                                $seen[abs($ruleLiteral)] = true;
                                break;
                            }
                        }

                        $l1retry = true;
                    }
                }
            }

            $decision = $this->decisions->atOffset($decisionId);
            $rule = $decision[Decisions::DECISION_REASON];
        }

        $why = \count($this->learnedPool) - 1;

        if (null === $learnedLiteral) {
            throw new SolverBugException(
                "Did not find a learnable literal in analyzed rule $analyzedRule."
            );
        }

        array_unshift($otherLearnedLiterals, $learnedLiteral);
        $newRule = new GenericRule($otherLearnedLiterals, Rule::RULE_LEARNED, $why);

        return [$learnedLiteral, $ruleLevel, $newRule, $why];
    }

    /**
     * @param array<string, true> $ruleSeen
     */
    private function analyzeUnsolvableRule(Problem $problem, Rule $conflictRule, array &$ruleSeen): void
    {
        $why = spl_object_hash($conflictRule);
        $ruleSeen[$why] = true;

        if ($conflictRule->getType() === RuleSet::TYPE_LEARNED) {
            $learnedWhy = $this->learnedWhy[$why];
            $problemRules = $this->learnedPool[$learnedWhy];

            foreach ($problemRules as $problemRule) {
                if (!isset($ruleSeen[spl_object_hash($problemRule)])) {
                    $this->analyzeUnsolvableRule($problem, $problemRule, $ruleSeen);
                }
            }

            return;
        }

        if ($conflictRule->getType() === RuleSet::TYPE_PACKAGE) {
            // package rules cannot be part of a problem
            return;
        }

        $problem->nextSection();
        $problem->addRule($conflictRule);
    }

    private function analyzeUnsolvable(Rule $conflictRule): void
    {
        $problem = new Problem();
        $problem->addRule($conflictRule);

        $ruleSeen = [];

        $this->analyzeUnsolvableRule($problem, $conflictRule, $ruleSeen);

        $this->problems[] = $problem;

        $seen = [];
        $literals = $conflictRule->getLiterals();

        foreach ($literals as $literal) {
            // skip the one true literal
            if ($this->decisions->satisfy($literal)) {
                continue;
            }
            $seen[abs($literal)] = true;
        }

        foreach ($this->decisions as $decision) {
            $decisionLiteral = $decision[Decisions::DECISION_LITERAL];

            // skip literals that are not in this rule
            if (!isset($seen[abs($decisionLiteral)])) {
                continue;
            }

            $why = $decision[Decisions::DECISION_REASON];

            $problem->addRule($why);
            $this->analyzeUnsolvableRule($problem, $why, $ruleSeen);

            $literals = $why->getLiterals();
            foreach ($literals as $literal) {
                // skip the one true literal
                if ($this->decisions->satisfy($literal)) {
                    continue;
                }
                $seen[abs($literal)] = true;
            }
        }
    }

    private function runSat(): void
    {
        $this->propagateIndex = 0;

        /*
         * here's the main loop:
         * 1) propagate new decisions (only needed once)
         * 2) fulfill root requires/fixed packages
         * 3) fulfill all unresolved rules
         * 4) minimalize solution if we had choices
         * if we encounter a problem, we rewind to a safe level and restart
         * with step 1
         */

        $level = 1;
        $systemLevel = $level + 1;

        while (true) {
            if (1 === $level) {
                $conflictRule = $this->propagate($level);
                if (null !== $conflictRule) {
                    $this->analyzeUnsolvable($conflictRule);

                    return;
                }
            }

            // handle root require/fixed package rules
            if ($level < $systemLevel) {
                $iterator = $this->rules->getIteratorFor(RuleSet::TYPE_REQUEST);
                foreach ($iterator as $rule) {
                    if ($rule->isEnabled()) {
                        $decisionQueue = [];
                        $noneSatisfied = true;

                        foreach ($rule->getLiterals() as $literal) {
                            if ($this->decisions->satisfy($literal)) {
                                $noneSatisfied = false;
                                break;
                            }
                            if ($literal > 0 && $this->decisions->undecided($literal)) {
                                $decisionQueue[] = $literal;
                            }
                        }

                        if ($noneSatisfied && \count($decisionQueue) > 0) {
                            // if any of the options in the decision queue are fixed, only use those
                            $prunedQueue = [];
                            foreach ($decisionQueue as $literal) {
                                if (isset($this->fixedMap[abs($literal)])) {
                                    $prunedQueue[] = $literal;
                                }
                            }
                            if (\count($prunedQueue) > 0) {
                                $decisionQueue = $prunedQueue;
                            }
                        }

                        if ($noneSatisfied && \count($decisionQueue) > 0) {
                            $oLevel = $level;
                            $level = $this->selectAndInstall($level, $decisionQueue, $rule);

                            if (0 === $level) {
                                return;
                            }
                            if ($level <= $oLevel) {
                                break;
                            }
                        }
                    }
                }

                $systemLevel = $level + 1;

                // root requires/fixed packages left
                $iterator->next();
                if ($iterator->valid()) {
                    continue;
                }
            }

            if ($level < $systemLevel) {
                $systemLevel = $level;
            }

            $rulesCount = \count($this->rules);
            $pass = 1;

            $this->io->writeError('Looking at all rules.', true, IOInterface::DEBUG);
            for ($i = 0, $n = 0; $n < $rulesCount; $i++, $n++) {
                if ($i === $rulesCount) {
                    if (1 === $pass) {
                        $this->io->writeError("Something's changed, looking at all rules again (pass #$pass)", false, IOInterface::DEBUG);
                    } else {
                        $this->io->overwriteError("Something's changed, looking at all rules again (pass #$pass)", false, null, IOInterface::DEBUG);
                    }

                    $i = 0;
                    $pass++;
                }

                $rule = $this->rules->ruleById[$i];
                $literals = $rule->getLiterals();

                if ($rule->isDisabled()) {
                    continue;
                }

                $decisionQueue = [];

                // make sure that
                // * all negative literals are installed
                // * no positive literal is installed
                // i.e. the rule is not fulfilled and we
                // just need to decide on the positive literals
                //
                foreach ($literals as $literal) {
                    if ($literal <= 0) {
                        if (!$this->decisions->decidedInstall($literal)) {
                            continue 2; // next rule
                        }
                    } else {
                        if ($this->decisions->decidedInstall($literal)) {
                            continue 2; // next rule
                        }
                        if ($this->decisions->undecided($literal)) {
                            $decisionQueue[] = $literal;
                        }
                    }
                }

                // need to have at least 2 item to pick from
                if (\count($decisionQueue) < 2) {
                    continue;
                }

                $level = $this->selectAndInstall($level, $decisionQueue, $rule);

                if (0 === $level) {
                    return;
                }

                // something changed, so look at all rules again
                $rulesCount = \count($this->rules);
                $n = -1;
            }

            if ($level < $systemLevel) {
                continue;
            }

            // minimization step
            if (\count($this->branches) > 0) {
                $lastLiteral = null;
                $lastLevel = null;
                $lastBranchIndex = 0;
                $lastBranchOffset = 0;

                for ($i = \count($this->branches) - 1; $i >= 0; $i--) {
                    [$literals, $l] = $this->branches[$i];

                    foreach ($literals as $offset => $literal) {
                        if ($literal > 0 && $this->decisions->decisionLevel($literal) > $l + 1) {
                            $lastLiteral = $literal;
                            $lastBranchIndex = $i;
                            $lastBranchOffset = $offset;
                            $lastLevel = $l;
                        }
                    }
                }

                if ($lastLiteral !== null && $lastLevel !== null) {
                    unset($this->branches[$lastBranchIndex][self::BRANCH_LITERALS][$lastBranchOffset]);

                    $level = $lastLevel;
                    $this->revert($level);

                    $why = $this->decisions->lastReason();

                    $level = $this->setPropagateLearn($level, $lastLiteral, $why);

                    if ($level === 0) {
                        return;
                    }

                    continue;
                }
            }

            break;
        }
    }
}
