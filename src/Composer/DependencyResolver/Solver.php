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
use Composer\Package\AliasPackage;
use Composer\DependencyResolver\Operation;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Solver
{
    protected $policy;
    protected $pool;
    protected $installed;
    protected $rules;
    protected $ruleSetGenerator;
    protected $updateAll;

    protected $addedMap = array();
    protected $updateMap = array();
    protected $watchGraph;
    protected $decisionMap;
    protected $installedMap;

    protected $decisionQueue = array();
    protected $decisionQueueWhy = array();
    protected $decisionQueueFree = array();
    protected $propagateIndex;
    protected $branches = array();
    protected $problems = array();
    protected $learnedPool = array();

    public function __construct(PolicyInterface $policy, Pool $pool, RepositoryInterface $installed)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->installed = $installed;
        $this->ruleSetGenerator = new RuleSetGenerator($policy, $pool);
    }

    private function findDecisionRule($packageId)
    {
        foreach ($this->decisionQueue as $i => $literal) {
            if ($packageId === abs($literal)) {
                return $this->decisionQueueWhy[$i];
            }
        }

        return null;
    }

    // aka solver_makeruledecisions
    private function makeAssertionRuleDecisions()
    {
        $decisionStart = count($this->decisionQueue);

        for ($ruleIndex = 0; $ruleIndex < count($this->rules); $ruleIndex++) {
            $rule = $this->rules->ruleById($ruleIndex);

            if (!$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            $literals = $rule->getLiterals();
            $literal = $literals[0];

            if (!$this->decided(abs($literal))) {
                $this->decide($literal, 1, $rule);
                continue;
            }

            if ($this->decisionsSatisfy($literal)) {
                continue;
            }

            // found a conflict
            if (RuleSet::TYPE_LEARNED === $rule->getType()) {
                $rule->disable();
                continue;
            }

            $conflict = $this->findDecisionRule(abs($literal));

            if ($conflict && RuleSet::TYPE_PACKAGE === $conflict->getType()) {

                $problem = new Problem;

                $problem->addRule($rule);
                $problem->addRule($conflict);
                $this->disableProblem($rule);
                $this->problems[] = $problem;
                continue;
            }

            // conflict with another job
            $problem = new Problem;
            $problem->addRule($rule);
            $problem->addRule($conflict);

            // push all of our rules (can only be job rules)
            // asserting this literal on the problem stack
            foreach ($this->rules->getIteratorFor(RuleSet::TYPE_JOB) as $assertRule) {
                if ($assertRule->isDisabled() || !$assertRule->isAssertion()) {
                    continue;
                }

                $assertRuleLiterals = $assertRule->getLiterals();
                $assertRuleLiteral = $assertRuleLiterals[0];

                if  (abs($literal) !== abs($assertRuleLiteral)) {
                    continue;
                }

                $problem->addRule($assertRule);
                $this->disableProblem($assertRule);
            }
            $this->problems[] = $problem;

            // start over
            while (count($this->decisionQueue) > $decisionStart) {
                $decisionLiteral = array_pop($this->decisionQueue);
                array_pop($this->decisionQueueWhy);
                unset($this->decisionQueueFree[count($this->decisionQueue)]);
                $this->decisionMap[abs($decisionLiteral)] = 0;
            }
            $ruleIndex = -1;
        }
    }

    protected function setupInstalledMap()
    {
        $this->installedMap = array();
        foreach ($this->installed->getPackages() as $package) {
            $this->installedMap[$package->getId()] = $package;
        }

        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'update':
                    foreach ($job['packages'] as $package) {
                        if (isset($this->installedMap[$package->getId()])) {
                            $this->updateMap[$package->getId()] = true;
                        }
                    }
                break;

                case 'update-all':
                    foreach ($this->installedMap as $package) {
                        $this->updateMap[$package->getId()] = true;
                    }
                break;

                case 'install':
                    if (!$job['packages']) {
                        $problem = new Problem();
                        $problem->addRule(new Rule($this->pool, array(), null, null, $job));
                        $this->problems[] = $problem;
                    }
                break;
            }
        }
    }

    public function solve(Request $request)
    {
        $this->jobs = $request->getJobs();

        $this->setupInstalledMap();

        if (version_compare(PHP_VERSION, '5.3.4', '>=')) {
            $this->decisionMap = new \SplFixedArray($this->pool->getMaxId() + 1);
        } else {
            $this->decisionMap = array_fill(0, $this->pool->getMaxId() + 1, 0);
        }

        $this->rules = $this->ruleSetGenerator->getRulesFor($this->jobs, $this->installedMap);
        $this->watchGraph = new RuleWatchGraph;

        foreach ($this->rules as $rule) {
            $this->watchGraph->insert(new RuleWatchNode($rule));
        }

        /* make decisions based on job/update assertions */
        $this->makeAssertionRuleDecisions();

        $this->runSat(true);

        if ($this->problems) {
            throw new SolverProblemsException($this->problems);
        }

        $transaction = new Transaction($this->policy, $this->pool, $this->installedMap, $this->decisionMap, $this->decisionQueue, $this->decisionQueueWhy);
        return $transaction->getOperations();
    }

    protected function literalFromId($id)
    {
        $package = $this->pool->packageById(abs($id));
        return new Literal($package, $id > 0);
    }

    protected function addDecision($literal, $level)
    {
        $packageId = abs($literal);

        $previousDecision = $this->decisionMap[$packageId];
        if ($previousDecision != 0) {
            $literalString = $this->pool->literalToString($literal);
            $package = $this->pool->literalToPackage($literal);
            throw new SolverBugException(
                "Trying to decide $literalString on level $level, even though $package was previously decided as ".(int) $previousDecision."."
            );
        }

        if ($literal > 0) {
            $this->decisionMap[$packageId] = $level;
        } else {
            $this->decisionMap[$packageId] = -$level;
        }
    }

    public function decide($literal, $level, $why)
    {
        $this->addDecision($literal, $level);
        $this->decisionQueue[] = $literal;
        $this->decisionQueueWhy[] = $why;
    }

    public function decisionsContain($literal)
    {
        $packageId = abs($literal);
        return (
            $this->decisionMap[$packageId] > 0 && $literal > 0 ||
            $this->decisionMap[$packageId] < 0 && $literal < 0
        );
    }

    protected function decisionsSatisfy($literal)
    {
        $packageId = abs($literal);
        return (
            $literal > 0 && $this->decisionMap[$packageId] > 0 ||
            $literal < 0 && $this->decisionMap[$packageId] < 0
        );
    }

    public function decisionsConflict($literal)
    {
        $packageId = abs($literal);
        return (
            ($this->decisionMap[$packageId] > 0 && $literal < 0) ||
            ($this->decisionMap[$packageId] < 0 && $literal > 0)
        );
    }
    protected function decided($packageId)
    {
        return $this->decisionMap[$packageId] != 0;
    }

    protected function undecided($packageId)
    {
        return $this->decisionMap[$packageId] == 0;
    }

    protected function decidedInstall($packageId) {
        return $this->decisionMap[$packageId] > 0;
    }

    /**
     * Makes a decision and propagates it to all rules.
     *
     * Evaluates each term affected by the decision (linked through watches)
     * If we find unit rules we make new decisions based on them
     *
     * @return Rule|null A rule on conflict, otherwise null.
     */
    protected function propagate($level)
    {
        while ($this->propagateIndex < count($this->decisionQueue)) {
            $conflict = $this->watchGraph->propagateLiteral(
                $this->decisionQueue[$this->propagateIndex++],
                $level,
                array($this, 'decisionsContain'),
                array($this, 'decisionsConflict'),
                array($this, 'decide')
            );

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
        while (!empty($this->decisionQueue)) {
            $literal = $this->decisionQueue[count($this->decisionQueue) - 1];

            if (!$this->decisionMap[abs($literal)]) {
                break;
            }

            $decisionLevel = abs($this->decisionMap[abs($literal)]);

            if ($decisionLevel <= $level) {
                break;
            }

            $this->decisionMap[abs($literal)] = 0;
            array_pop($this->decisionQueue);
            array_pop($this->decisionQueueWhy);

            $this->propagateIndex = count($this->decisionQueue);
        }

        while (!empty($this->branches)) {
            list($literals, $branchLevel) = $this->branches[count($this->branches) - 1];

            if ($branchLevel >= $level) {
                break;
            }

            array_pop($this->branches);
        }
    }

    /**-------------------------------------------------------------------
     *
     * setpropagatelearn
     *
     * add free decision (solvable to install) to decisionq
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

        $this->decide($literal, $level, $rule);
        $this->decisionQueueFree[count($this->decisionQueueWhy) - 1] = true;

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
            } else if (!$newRule) {
                throw new SolverBugException(
                    "No rule was learned from analyzing $rule at level $level."
                );
            }

            $level = $newLevel;

            $this->revert($level);

            $this->rules->add($newRule, RuleSet::TYPE_LEARNED);

            $this->learnedWhy[$newRule->getId()] = $why;

            $ruleNode = new RuleWatchNode($newRule);
            $ruleNode->watch2OnHighest($this->decisionMap);
            $this->watchGraph->insert($ruleNode);

            $this->decide($learnLiteral, $level, $newRule);
        }

        return $level;
    }

    private function selectAndInstall($level, array $decisionQueue, $disableRules, Rule $rule)
    {
        // choose best package to install from decisionQueue
        $literals = $this->policy->selectPreferedPackages($this->pool, $this->installedMap, $decisionQueue);

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

        $decisionId = count($this->decisionQueue);

        $this->learnedPool[] = array();

        while(true) {
            $this->learnedPool[count($this->learnedPool) - 1][] = $rule;

            foreach ($rule->getLiterals() as $literal) {
                // skip the one true literal
                if ($this->decisionsSatisfy($literal)) {
                    continue;
                }

                if (isset($seen[abs($literal)])) {
                    continue;
                }
                $seen[abs($literal)] = true;

                $l = abs($this->decisionMap[abs($literal)]);

                if (1 === $l) {
                    $l1num++;
                } else if ($level === $l) {
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

                    $literal = $this->decisionQueue[$decisionId];

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

            $rule = $this->decisionQueueWhy[$decisionId];
        }

        $why = count($this->learnedPool) - 1;

        if (!$learnedLiterals[0]) {
            throw new SolverBugException(
                "Did not find a learnable literal in analyzed rule $analyzedRule."
            );
        }

        $newRule = new Rule($this->pool, $learnedLiterals, Rule::RULE_LEARNED, $why);

        return array($learnedLiterals[0], $ruleLevel, $newRule, $why);
    }

    private function analyzeUnsolvableRule($problem, $conflictRule)
    {
        $why = $conflictRule->getId();

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

        $problem->addRule($conflictRule);
    }

    private function analyzeUnsolvable($conflictRule, $disableRules)
    {
        $problem = new Problem;
        $problem->addRule($conflictRule);

        $this->analyzeUnsolvableRule($problem, $conflictRule);

        $this->problems[] = $problem;

        $seen = array();
        $literals = $conflictRule->getLiterals();

        foreach ($literals as $literal) {
            // skip the one true literal
            if ($this->decisionsSatisfy($literal)) {
                continue;
            }
            $seen[abs($literal)] = true;
        }

        $decisionId = count($this->decisionQueue);

        while ($decisionId > 0) {
            $decisionId--;

            $literal = $this->decisionQueue[$decisionId];

            // skip literals that are not in this rule
            if (!isset($seen[abs($literal)])) {
                continue;
            }

            $why = $this->decisionQueueWhy[$decisionId];
            $problem->addRule($why);

            $this->analyzeUnsolvableRule($problem, $why);

            $literals = $why->getLiterals();

            foreach ($literals as $literal) {
                // skip the one true literal
                if ($this->decisionsSatisfy($literal)) {
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
        } else {
            // disable all rules of this job
            foreach ($this->rules as $rule) {
                if ($job === $rule->getJob()) {
                    $rule->disable();
                }
            }
        }
    }

    private function resetSolver()
    {
        while ($literal = array_pop($this->decisionQueue)) {
            $this->decisionMap[abs($literal)] = 0;
        }

        $this->decisionQueueWhy = array();
        $this->decisionQueueFree = array();
        $this->propagateIndex = 0;
        $this->branches = array();

        $this->enableDisableLearnedRules();
        $this->makeAssertionRuleDecisions();
    }

    /*-------------------------------------------------------------------
    * enable/disable learnt rules
    *
    * we have enabled or disabled some of our rules. We now reenable all
    * of our learnt rules except the ones that were learnt from rules that
    * are now disabled.
    */
    private function enableDisableLearnedRules()
    {
        foreach ($this->rules->getIteratorFor(RuleSet::TYPE_LEARNED) as $rule) {
            $why = $this->learnedWhy[$rule->getId()];
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
            } else if (!$foundDisabled && $rule->isDisabled()) {
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
        $minimizationSteps = 0;
        $installedPos = 0;

        while (true) {

            if (1 === $level) {
                $conflictRule = $this->propagate($level);
                if ($conflictRule !== null) {
                    if ($this->analyzeUnsolvable($conflictRule, $disableRules)) {
                        continue;
                    } else {
                        return;
                    }
                }
            }

            // handle job rules
            if ($level < $systemLevel) {
                $iterator = $this->rules->getIteratorFor(RuleSet::TYPE_JOB);
                foreach ($iterator as $rule) {
                    if ($rule->isEnabled()) {
                        $decisionQueue = array();
                        $noneSatisfied = true;

                        foreach ($rule->getLiterals() as $literal) {
                            if ($this->decisionsSatisfy($literal)) {
                                $noneSatisfied = false;
                                break;
                            }
                            if ($literal > 0 && $this->undecided($literal)) {
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

                $rule = $this->rules->ruleById($i);
                $literals = $rule->getLiterals();

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
                        if (!$this->decidedInstall(abs($literal))) {
                            continue 2; // next rule
                        }
                    } else {
                        if ($this->decidedInstall(abs($literal))) {
                            continue 2; // next rule
                        }
                        if ($this->undecided(abs($literal))) {
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

                for ($i = count($this->branches) - 1; $i >= 0; $i--) {
                    list($literals, $level) = $this->branches[$i];

                    foreach ($literals as $offset => $literal) {
                        if ($literal && $literal > 0 && $this->decisionMap[abs($literal)] > $level + 1) {
                            $lastLiteral = $literal;
                            $lastBranchIndex = $i;
                            $lastBranchOffset = $offset;
                            $lastLevel = $level;
                        }
                    }
                }

                if ($lastLiteral) {
                    $this->branches[$lastBranchIndex][$lastBranchOffset] = null;
                    $minimizationSteps++;

                    $level = $lastLevel;
                    $this->revert($level);

                    $why = $this->decisionQueueWhy[count($this->decisionQueueWhy) - 1];

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
