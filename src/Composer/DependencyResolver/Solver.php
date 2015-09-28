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

namespace Composer\DependencyResolver;

use Composer\Repository\RepositoryInterface;
use Composer\Repository\PlatformRepository;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Solver
{
    const BRANCH_LITERALS = 0;
    const BRANCH_LEVEL = 1;

    protected $policy;
    protected $pool;
    protected $installed;
    protected $rules;
    protected $ruleSetGenerator;
    protected $updateAll;

    protected $addedMap = array();
    protected $updateMap = array();
    protected $watchGraph;
    protected $decisions;
    protected $installedMap;

    protected $propagateIndex;
    protected $branches = array();
    protected $problems = array();
    protected $learnedPool = array();
    protected $learnedWhy = array();

    public function __construct(PolicyInterface $policy, Pool $pool, RepositoryInterface $installed)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->installed = $installed;
        $this->ruleSetGenerator = new RuleSetGenerator($policy, $pool);
    }

    public function getRuleSetSize()
    {
        return count($this->rules);
    }

    // aka solver_makeruledecisions
    private function makeAssertionRuleDecisions()
    {
        $decisionStart = count($this->decisions) - 1;

        $rulesCount = count($this->rules);
        for ($ruleIndex = 0; $ruleIndex < $rulesCount; $ruleIndex++) {
            $rule = $this->rules->ruleById[$ruleIndex];

            if (!$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            $literals = $rule->literals;
            $literal = $literals[0];

            if (!$this->decisions->decided(abs($literal))) {
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

            if ($conflict && RuleSet::TYPE_PACKAGE === $conflict->getType()) {
                $problem = new Problem($this->pool);

                $problem->addRule($rule);
                $problem->addRule($conflict);
                $this->disableProblem($rule);
                $this->problems[] = $problem;
                continue;
            }

            // conflict with another job
            $problem = new Problem($this->pool);
            $problem->addRule($rule);
            $problem->addRule($conflict);

            // push all of our rules (can only be job rules)
            // asserting this literal on the problem stack
            foreach ($this->rules->getIteratorFor(RuleSet::TYPE_JOB) as $assertRule) {
                if ($assertRule->isDisabled() || !$assertRule->isAssertion()) {
                    continue;
                }

                $assertRuleLiterals = $assertRule->literals;
                $assertRuleLiteral = $assertRuleLiterals[0];

                if (abs($literal) !== abs($assertRuleLiteral)) {
                    continue;
                }

                $problem->addRule($assertRule);
                $this->disableProblem($assertRule);
            }
            $this->problems[] = $problem;

            $this->decisions->resetToOffset($decisionStart);
            $ruleIndex = -1;
        }
    }

    protected function setupInstalledMap()
    {
        $this->installedMap = array();
        foreach ($this->installed->getPackages() as $package) {
            $this->installedMap[$package->id] = $package;
        }
    }

    protected function checkForRootRequireProblems($ignorePlatformReqs)
    {
        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'update':
                    $packages = $this->pool->whatProvides($job['packageName'], $job['constraint']);
                    foreach ($packages as $package) {
                        if (isset($this->installedMap[$package->id])) {
                            $this->updateMap[$package->id] = true;
                        }
                    }
                    break;

                case 'update-all':
                    foreach ($this->installedMap as $package) {
                        $this->updateMap[$package->id] = true;
                    }
                    break;

                case 'install':
                    if ($ignorePlatformReqs && preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $job['packageName'])) {
                        break;
                    }

                    if (!$this->pool->whatProvides($job['packageName'], $job['constraint'])) {
                        $problem = new Problem($this->pool);
                        $problem->addRule(new Rule(array(), null, null, $job));
                        $this->problems[] = $problem;
                    }
                    break;
            }
        }
    }

    public function solve(Request $request, $ignorePlatformReqs = false)
    {
        $this->jobs = $request->getJobs();

        $this->setupInstalledMap();
        $this->rules = $this->ruleSetGenerator->getRulesFor($this->jobs, $this->installedMap, $ignorePlatformReqs);
        $this->checkForRootRequireProblems($ignorePlatformReqs);
        $this->decisions = new Decisions($this->pool);
        $this->watchGraph = new RuleWatchGraph;

        foreach ($this->rules as $rule) {
            $this->watchGraph->insert(new RuleWatchNode($rule));
        }

        /* make decisions based on job/update assertions */
        $this->makeAssertionRuleDecisions();

        $this->runSat(true);

        // decide to remove everything that's installed and undecided
        foreach ($this->installedMap as $packageId => $void) {
            if ($this->decisions->undecided($packageId)) {
                $this->decisions->decide(-$packageId, 1, null);
            }
        }

        if ($this->problems) {
            throw new SolverProblemsException($this->problems, $this->installedMap);
        }

        $transaction = new Transaction($this->policy, $this->pool, $this->installedMap, $this->decisions);

        return $transaction->getOperations();
    }

    protected function literalFromId($id)
    {
        $package = $this->pool->packageById(abs($id));

        return new Literal($package, $id > 0);
    }

    /**
     * Makes a decision and propagates it to all rules.
     *
     * Evaluates each term affected by the decision (linked through watches)
     * If we find unit rules we make new decisions based on them
     *
     * @param  int       $level
     * @return Rule|null A rule on conflict, otherwise null.
     */
    protected function propagate($level)
    {
        while ($this->decisions->validOffset($this->propagateIndex)) {
            $decision = $this->decisions->atOffset($this->propagateIndex);

            $conflict = $this->watchGraph->propagateLiteral(
                $decision[Decisions::DECISION_LITERAL],
                $level,
                $this->decisions
            );

            $this->propagateIndex++;

            if ($conflict) {
                return $conflict;
            }
        }

        return null;
    }

    /**
     * Reverts a decision at the given level.
     */
    private function revert($level)
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
            $this->propagateIndex = count($this->decisions);
        }

        while (!empty($this->branches) && $this->branches[count($this->branches) - 1][self::BRANCH_LEVEL] >= $level) {
            array_pop($this->branches);
        }
    }

    /**-------------------------------------------------------------------
     *
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
     *
     */
    private function setPropagateLearn($level, $literal, $disableRules, Rule $rule)
    {
        $level++;

        $this->decisions->decide($literal, $level, $rule);

        while (true) {
            $rule = $this->propagate($level);

            if (!$rule) {
                break;
            }

            if ($level == 1) {
                return $this->analyzeUnsolvable($rule, $disableRules);
            }

            // conflict
            list($learnLiteral, $newLevel, $newRule, $why) = $this->analyze($level, $rule);

            if ($newLevel <= 0 || $newLevel >= $level) {
                throw new SolverBugException(
                    "Trying to revert to invalid level ".(int) $newLevel." from level ".(int) $level."."
                );
            } elseif (!$newRule) {
                throw new SolverBugException(
                    "No rule was learned from analyzing $rule at level $level."
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

    private function selectAndInstall($level, array $decisionQueue, $disableRules, Rule $rule)
    {
        // choose best package to install from decisionQueue
        $literals = $this->policy->selectPreferredPackages($this->pool, $this->installedMap, $decisionQueue, $rule->getRequiredPackage());

        $selectedLiteral = array_shift($literals);

        // if there are multiple candidates, then branch
        if (count($literals)) {
            $this->branches[] = array($literals, $level);
        }

        return $this->setPropagateLearn($level, $selectedLiteral, $disableRules, $rule);
    }

    protected function analyze($level, $rule)
    {
        $analyzedRule = $rule;
        $ruleLevel = 1;
        $num = 0;
        $l1num = 0;
        $seen = array();
        $learnedLiterals = array(null);

        $decisionId = count($this->decisions);

        $this->learnedPool[] = array();

        while (true) {
            $this->learnedPool[count($this->learnedPool) - 1][] = $rule;

            foreach ($rule->literals as $literal) {
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
                    $learnedLiterals[] = $literal;

                    if ($l > $ruleLevel) {
                        $ruleLevel = $l;
                    }
                }
            }

            $l1retry = true;
            while ($l1retry) {
                $l1retry = false;

                if (!$num && !--$l1num) {
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

                if ($num && 0 === --$num) {
                    $learnedLiterals[0] = -abs($literal);

                    if (!$l1num) {
                        break 2;
                    }

                    foreach ($learnedLiterals as $i => $learnedLiteral) {
                        if ($i !== 0) {
                            unset($seen[abs($learnedLiteral)]);
                        }
                    }
                    // only level 1 marks left
                    $l1num++;
                    $l1retry = true;
                }
            }

            $decision = $this->decisions->atOffset($decisionId);
            $rule = $decision[Decisions::DECISION_REASON];
        }

        $why = count($this->learnedPool) - 1;

        if (!$learnedLiterals[0]) {
            throw new SolverBugException(
                "Did not find a learnable literal in analyzed rule $analyzedRule."
            );
        }

        $newRule = new Rule($learnedLiterals, Rule::RULE_LEARNED, $why);

        return array($learnedLiterals[0], $ruleLevel, $newRule, $why);
    }

    private function analyzeUnsolvableRule($problem, $conflictRule)
    {
        $why = spl_object_hash($conflictRule);

        if ($conflictRule->getType() == RuleSet::TYPE_LEARNED) {
            $learnedWhy = $this->learnedWhy[$why];
            $problemRules = $this->learnedPool[$learnedWhy];

            foreach ($problemRules as $problemRule) {
                $this->analyzeUnsolvableRule($problem, $problemRule);
            }

            return;
        }

        if ($conflictRule->getType() == RuleSet::TYPE_PACKAGE) {
            // package rules cannot be part of a problem
            return;
        }

        $problem->nextSection();
        $problem->addRule($conflictRule);
    }

    private function analyzeUnsolvable($conflictRule, $disableRules)
    {
        $problem = new Problem($this->pool);
        $problem->addRule($conflictRule);

        $this->analyzeUnsolvableRule($problem, $conflictRule);

        $this->problems[] = $problem;

        $seen = array();
        $literals = $conflictRule->literals;

        foreach ($literals as $literal) {
            // skip the one true literal
            if ($this->decisions->satisfy($literal)) {
                continue;
            }
            $seen[abs($literal)] = true;
        }

        foreach ($this->decisions as $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];

            // skip literals that are not in this rule
            if (!isset($seen[abs($literal)])) {
                continue;
            }

            $why = $decision[Decisions::DECISION_REASON];

            $problem->addRule($why);
            $this->analyzeUnsolvableRule($problem, $why);

            $literals = $why->literals;

            foreach ($literals as $literal) {
                // skip the one true literal
                if ($this->decisions->satisfy($literal)) {
                    continue;
                }
                $seen[abs($literal)] = true;
            }
        }

        if ($disableRules) {
            foreach ($this->problems[count($this->problems) - 1] as $reason) {
                $this->disableProblem($reason['rule']);
            }

            $this->resetSolver();

            return 1;
        }

        return 0;
    }

    private function disableProblem($why)
    {
        $job = $why->getJob();

        if (!$job) {
            $why->disable();

            return;
        }

        // disable all rules of this job
        foreach ($this->rules as $rule) {
            if ($job === $rule->getJob()) {
                $rule->disable();
            }
        }
    }

    private function resetSolver()
    {
        $this->decisions->reset();

        $this->propagateIndex = 0;
        $this->branches = array();

        $this->enableDisableLearnedRules();
        $this->makeAssertionRuleDecisions();
    }

    /*-------------------------------------------------------------------
    * enable/disable learnt rules
    *
    * we have enabled or disabled some of our rules. We now re-enable all
    * of our learnt rules except the ones that were learnt from rules that
    * are now disabled.
    */
    private function enableDisableLearnedRules()
    {
        foreach ($this->rules->getIteratorFor(RuleSet::TYPE_LEARNED) as $rule) {
            $why = $this->learnedWhy[spl_object_hash($rule)];
            $problemRules = $this->learnedPool[$why];

            $foundDisabled = false;
            foreach ($problemRules as $problemRule) {
                if ($problemRule->isDisabled()) {
                    $foundDisabled = true;
                    break;
                }
            }

            if ($foundDisabled && $rule->isEnabled()) {
                $rule->disable();
            } elseif (!$foundDisabled && $rule->isDisabled()) {
                $rule->enable();
            }
        }
    }

    private function runSat($disableRules = true)
    {
        $this->propagateIndex = 0;

        //   /*
        //    * here's the main loop:
        //    * 1) propagate new decisions (only needed once)
        //    * 2) fulfill jobs
        //    * 3) fulfill all unresolved rules
        //    * 4) minimalize solution if we had choices
        //    * if we encounter a problem, we rewind to a safe level and restart
        //    * with step 1
        //    */

        $decisionQueue = array();
        $decisionSupplementQueue = array();
        $disableRules = array();

        $level = 1;
        $systemLevel = $level + 1;
        $installedPos = 0;

        while (true) {
            if (1 === $level) {
                $conflictRule = $this->propagate($level);
                if (null !== $conflictRule) {
                    if ($this->analyzeUnsolvable($conflictRule, $disableRules)) {
                        continue;
                    }

                    return;
                }
            }

            // handle job rules
            if ($level < $systemLevel) {
                $iterator = $this->rules->getIteratorFor(RuleSet::TYPE_JOB);
                foreach ($iterator as $rule) {
                    if ($rule->isEnabled()) {
                        $decisionQueue = array();
                        $noneSatisfied = true;

                        foreach ($rule->literals as $literal) {
                            if ($this->decisions->satisfy($literal)) {
                                $noneSatisfied = false;
                                break;
                            }
                            if ($literal > 0 && $this->decisions->undecided($literal)) {
                                $decisionQueue[] = $literal;
                            }
                        }

                        if ($noneSatisfied && count($decisionQueue)) {
                            // prune all update packages until installed version
                            // except for requested updates
                            if (count($this->installed) != count($this->updateMap)) {
                                $prunedQueue = array();
                                foreach ($decisionQueue as $literal) {
                                    if (isset($this->installedMap[abs($literal)])) {
                                        $prunedQueue[] = $literal;
                                        if (isset($this->updateMap[abs($literal)])) {
                                            $prunedQueue = $decisionQueue;
                                            break;
                                        }
                                    }
                                }
                                $decisionQueue = $prunedQueue;
                            }
                        }

                        if ($noneSatisfied && count($decisionQueue)) {
                            $oLevel = $level;
                            $level = $this->selectAndInstall($level, $decisionQueue, $disableRules, $rule);

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

                // jobs left
                $iterator->next();
                if ($iterator->valid()) {
                    continue;
                }
            }

            if ($level < $systemLevel) {
                $systemLevel = $level;
            }

            for ($i = 0, $n = 0; $n < count($this->rules); $i++, $n++) {
                if ($i == count($this->rules)) {
                    $i = 0;
                }

                $rule = $this->rules->ruleById[$i];
                $literals = $rule->literals;

                if ($rule->isDisabled()) {
                    continue;
                }

                $decisionQueue = array();

                // make sure that
                // * all negative literals are installed
                // * no positive literal is installed
                // i.e. the rule is not fulfilled and we
                // just need to decide on the positive literals
                //
                foreach ($literals as $literal) {
                    if ($literal <= 0) {
                        if (!$this->decisions->decidedInstall(abs($literal))) {
                            continue 2; // next rule
                        }
                    } else {
                        if ($this->decisions->decidedInstall(abs($literal))) {
                            continue 2; // next rule
                        }
                        if ($this->decisions->undecided(abs($literal))) {
                            $decisionQueue[] = $literal;
                        }
                    }
                }

                // need to have at least 2 item to pick from
                if (count($decisionQueue) < 2) {
                    continue;
                }

                $oLevel = $level;
                $level = $this->selectAndInstall($level, $decisionQueue, $disableRules, $rule);

                if (0 === $level) {
                    return;
                }

                // something changed, so look at all rules again
                $n = -1;
            }

            if ($level < $systemLevel) {
                continue;
            }

            // minimization step
            if (count($this->branches)) {
                $lastLiteral = null;
                $lastLevel = null;
                $lastBranchIndex = 0;
                $lastBranchOffset  = 0;
                $l = 0;

                for ($i = count($this->branches) - 1; $i >= 0; $i--) {
                    list($literals, $l) = $this->branches[$i];

                    foreach ($literals as $offset => $literal) {
                        if ($literal && $literal > 0 && $this->decisions->decisionLevel($literal) > $l + 1) {
                            $lastLiteral = $literal;
                            $lastBranchIndex = $i;
                            $lastBranchOffset = $offset;
                            $lastLevel = $l;
                        }
                    }
                }

                if ($lastLiteral) {
                    unset($this->branches[$lastBranchIndex][self::BRANCH_LITERALS][$lastBranchOffset]);

                    $level = $lastLevel;
                    $this->revert($level);

                    $why = $this->decisions->lastReason();

                    $oLevel = $level;
                    $level = $this->setPropagateLearn($level, $lastLiteral, $disableRules, $why);

                    if ($level == 0) {
                        return;
                    }

                    continue;
                }
            }

            break;
        }
    }
}
