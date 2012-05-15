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
use Composer\Package\PackageInterface;
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
    protected $updateAll;

    protected $ruleToJob = array();
    protected $addedMap = array();
    protected $updateMap = array();
    protected $watches = array();
    protected $decisionMap;
    protected $installedMap;

    protected $decisionQueue = array();
    protected $decisionQueueWhy = array();
    protected $decisionQueueFree = array();
    protected $propagateIndex;
    protected $branches = array();
    protected $problems = array();
    protected $learnedPool = array();
    protected $recommendsIndex;

    public function __construct(PolicyInterface $policy, Pool $pool, RepositoryInterface $installed)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->installed = $installed;
        $this->rules = new RuleSet;
    }

    /**
     * Creates a new rule for the requirements of a package
     *
     * This rule is of the form (-A|B|C), where B and C are the providers of
     * one requirement of the package A.
     *
     * @param PackageInterface $package    The package with a requirement
     * @param array            $providers  The providers of the requirement
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the requirement name,
     *                                     that goes with the reason
     * @return Rule                        The generated rule or null if tautological
     */
    protected function createRequireRule(PackageInterface $package, array $providers, $reason, $reasonData = null)
    {
        $literals = array(new Literal($package, false));

        foreach ($providers as $provider) {
            // self fulfilling rule?
            if ($provider === $package) {
                return null;
            }
            $literals[] = new Literal($provider, true);
        }

        return new Rule($literals, $reason, $reasonData);
    }

    /**
     * Creates a new rule for installing a package
     *
     * The rule is simply (A) for a package A to be installed.
     *
     * @param PackageInterface $package    The package to be installed
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    protected function createInstallRule(PackageInterface $package, $reason, $reasonData = null)
    {
        return new Rule(new Literal($package, true));
    }

    /**
     * Creates a rule to install at least one of a set of packages
     *
     * The rule is (A|B|C) with A, B and C different packages. If the given
     * set of packages is empty an impossible rule is generated.
     *
     * @param array   $packages   The set of packages to choose from
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               The generated rule
     */
    protected function createInstallOneOfRule(array $packages, $reason, $reasonData = null)
    {
        $literals = array();
        foreach ($packages as $package) {
            $literals[] = new Literal($package, true);
        }

        return new Rule($literals, $reason, $reasonData);
    }

    /**
     * Creates a rule to remove a package
     *
     * The rule for a package A is (-A).
     *
     * @param PackageInterface $package    The package to be removed
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    protected function createRemoveRule(PackageInterface $package, $reason, $reasonData = null)
    {
        return new Rule(array(new Literal($package, false)), $reason, $reasonData);
    }

    /**
     * Creates a rule for two conflicting packages
     *
     * The rule for conflicting packages A and B is (-A|-B). A is called the issuer
     * and B the provider.
     *
     * @param PackageInterface $issuer     The package declaring the conflict
     * @param Package          $provider   The package causing the conflict
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    protected function createConflictRule(PackageInterface $issuer, PackageInterface $provider, $reason, $reasonData = null)
    {
        // ignore self conflict
        if ($issuer === $provider) {
            return null;
        }

        return new Rule(array(new Literal($issuer, false), new Literal($provider, false)), $reason, $reasonData);
    }

    /**
     * Adds a rule unless it duplicates an existing one of any type
     *
     * To be able to directly pass in the result of one of the rule creation
     * methods the rule may also be null to indicate that no rule should be
     * added.
     *
     * @param int  $type    A TYPE_* constant defining the rule type
     * @param Rule $newRule The rule about to be added
     */
    private function addRule($type, Rule $newRule = null) {
        if ($newRule) {
            if ($this->rules->containsEqual($newRule)) {
                return;
            }

            $this->rules->add($newRule, $type);
        }
    }

    protected function addRulesForPackage(PackageInterface $package)
    {
        $workQueue = new \SplQueue;
        $workQueue->enqueue($package);

        while (!$workQueue->isEmpty()) {
            $package = $workQueue->dequeue();
            if (isset($this->addedMap[$package->getId()])) {
                continue;
            }

            $this->addedMap[$package->getId()] = true;

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                $this->addRule(RuleSet::TYPE_PACKAGE, $rule = $this->createRequireRule($package, $possibleRequires, Rule::RULE_PACKAGE_REQUIRES, (string) $link));

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }

            foreach ($package->getConflicts() as $link) {
                $possibleConflicts = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                foreach ($possibleConflicts as $conflict) {
                    $this->addRule(RuleSet::TYPE_PACKAGE, $this->createConflictRule($package, $conflict, Rule::RULE_PACKAGE_CONFLICT, (string) $link));
                }
            }

            // check obsoletes and implicit obsoletes of a package
            $isInstalled = (isset($this->installedMap[$package->getId()]));

            foreach ($package->getReplaces() as $link) {
                $obsoleteProviders = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                foreach ($obsoleteProviders as $provider) {
                    if ($provider === $package) {
                        continue;
                    }

                    if (!$this->obsoleteImpossibleForAlias($package, $provider)) {
                        $reason = ($isInstalled) ? Rule::RULE_INSTALLED_PACKAGE_OBSOLETES : Rule::RULE_PACKAGE_OBSOLETES;
                        $this->addRule(RuleSet::TYPE_PACKAGE, $this->createConflictRule($package, $provider, $reason, (string) $link));
                    }
                }
            }

            // check implicit obsoletes
            // for installed packages we only need to check installed/installed problems,
            // as the others are picked up when looking at the uninstalled package.
            if (!$isInstalled) {
                $obsoleteProviders = $this->pool->whatProvides($package->getName(), null);

                foreach ($obsoleteProviders as $provider) {
                    if ($provider === $package) {
                        continue;
                    }

                    if (($package instanceof AliasPackage) && $package->getAliasOf() === $provider) {
                        $this->addRule(RuleSet::TYPE_PACKAGE, $rule = $this->createRequireRule($package, array($provider), Rule::RULE_PACKAGE_ALIAS, (string) $package));
                    } else if (!$this->obsoleteImpossibleForAlias($package, $provider)) {
                        $reason = ($package->getName() == $provider->getName()) ? Rule::RULE_PACKAGE_SAME_NAME : Rule::RULE_PACKAGE_IMPLICIT_OBSOLETES;
                        $this->addRule(RuleSet::TYPE_PACKAGE, $rule = $this->createConflictRule($package, $provider, $reason, (string) $package));
                    }
                }
            }
        }
    }

    protected function obsoleteImpossibleForAlias($package, $provider)
    {
        $packageIsAlias = $package instanceof AliasPackage;
        $providerIsAlias = $provider instanceof AliasPackage;

        $impossible = (
            ($packageIsAlias && $package->getAliasOf() === $provider) ||
            ($providerIsAlias && $provider->getAliasOf() === $package) ||
            ($packageIsAlias && $providerIsAlias && $provider->getAliasOf() === $package->getAliasOf())
        );

        return $impossible;
    }

    /**
     * Adds all rules for all update packages of a given package
     *
     * @param PackageInterface $package  Rules for this package's updates are to
     *                                   be added
     * @param bool             $allowAll Whether downgrades are allowed
     */
    private function addRulesForUpdatePackages(PackageInterface $package)
    {
        $updates = $this->policy->findUpdatePackages($this->pool, $this->installedMap, $package);

        $this->addRulesForPackage($package);

        foreach ($updates as $update) {
            $this->addRulesForPackage($update);
        }
    }

    /**
     * Alters watch chains for a rule.
     *
     * Next1/2 always points to the next rule that is watching the same package.
     * The watches array contains rules to start from for each package
     *
     */
    private function addWatchesToRule(Rule $rule)
    {
        // skip simple assertions of the form (A) or (-A)
        if ($rule->isAssertion()) {
            return;
        }

        if (!isset($this->watches[$rule->watch1])) {
            $this->watches[$rule->watch1] = null;
        }

        $rule->next1 = $this->watches[$rule->watch1];
        $this->watches[$rule->watch1] = $rule;

        if (!isset($this->watches[$rule->watch2])) {
            $this->watches[$rule->watch2] = null;
        }

        $rule->next2 = $this->watches[$rule->watch2];
        $this->watches[$rule->watch2] = $rule;
    }

    /**
     * Put watch2 on rule's literal with highest level
     */
    private function watch2OnHighest(Rule $rule)
    {
        $literals = $rule->getLiterals();

        // if there are only 2 elements, both are being watched anyway
        if ($literals < 3) {
            return;
        }

        $watchLevel = 0;

        foreach ($literals as $literal) {
            $level = abs($this->decisionMap[$literal->getPackageId()]);

            if ($level > $watchLevel) {
                $rule->watch2 = $literal->getId();
                $watchLevel = $level;
            }
        }
    }

    private function findDecisionRule(PackageInterface $package)
    {
        foreach ($this->decisionQueue as $i => $literal) {
            if ($package === $literal->getPackage()) {
                return $this->decisionQueueWhy[$i];
            }
        }

        return null;
    }

    // aka solver_makeruledecisions
    private function makeAssertionRuleDecisions()
    {
        // do we need to decide a SYSTEMSOLVABLE at level 1?

        $decisionStart = count($this->decisionQueue);

        for ($ruleIndex = 0; $ruleIndex < count($this->rules); $ruleIndex++) {
            $rule = $this->rules->ruleById($ruleIndex);

            if ($rule->isWeak() || !$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            $literals = $rule->getLiterals();
            $literal = $literals[0];

            if (!$this->decided($literal->getPackage())) {
                $this->decisionQueue[] = $literal;
                $this->decisionQueueWhy[] = $rule;
                $this->addDecision($literal, 1);
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

            $conflict = $this->findDecisionRule($literal->getPackage());
            /** TODO: handle conflict with systemsolvable? */

            if ($conflict && RuleSet::TYPE_PACKAGE === $conflict->getType()) {

                $problem = new Problem;

                if ($rule->getType() == RuleSet::TYPE_JOB) {
                    $job = $this->ruleToJob[$rule->getId()];

                    $problem->addJobRule($job, $rule);
                    $problem->addRule($conflict);
                    $this->disableProblem($job);
                } else {
                    $problem->addRule($rule);
                    $problem->addRule($conflict);
                    $this->disableProblem($rule);
                }
                $this->problems[] = $problem;
                continue;
            }

            // conflict with another job or update/feature rule
            $problem = new Problem;
            $problem->addRule($rule);
            $problem->addRule($conflict);

            // push all of our rules (can only be feature or job rules)
            // asserting this literal on the problem stack
            foreach ($this->rules->getIteratorFor(RuleSet::TYPE_JOB) as $assertRule) {
                if ($assertRule->isDisabled() || !$assertRule->isAssertion() || $assertRule->isWeak()) {
                    continue;
                }

                $assertRuleLiterals = $assertRule->getLiterals();
                $assertRuleLiteral = $assertRuleLiterals[0];

                if  ($literal->getPackageId() !== $assertRuleLiteral->getPackageId()) {
                    continue;
                }

                if ($assertRule->getType() === RuleSet::TYPE_JOB) {
                    $job = $this->ruleToJob[$assertRule->getId()];

                    $problem->addJobRule($job, $assertRule);
                    $this->disableProblem($job);
                } else {
                    $problem->addRule($assertRule);
                    $this->disableProblem($assertRule);
                }
            }
            $this->problems[] = $problem;

            // start over
            while (count($this->decisionQueue) > $decisionStart) {
                $decisionLiteral = array_pop($this->decisionQueue);
                array_pop($this->decisionQueueWhy);
                unset($this->decisionQueueFree[count($this->decisionQueue)]);
                $this->decisionMap[$decisionLiteral->getPackageId()] = 0;
            }
            $ruleIndex = -1;
        }

        foreach ($this->rules as $rule) {
            if (!$rule->isWeak() || !$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            $literals = $rule->getLiterals();
            $literal = $literals[0];

            if ($this->decisionMap[$literal->getPackageId()] == 0) {
                $this->decisionQueue[] = $literal;
                $this->decisionQueueWhy[] = $rule;
                $this->addDecision($literal, 1);
                continue;
            }

            if ($this->decisionsSatisfy($literals[0])) {
                continue;
            }

            // conflict, but this is a weak rule => disable
            if ($rule->getType() == RuleSet::TYPE_JOB) {
                $why = $this->ruleToJob[$rule->getId()];
            } else {
                $why = $rule;
            }

            $this->disableProblem($why);
        }
    }

    protected function setupInstalledMap()
    {
        $this->installedMap = array();
        foreach ($this->installed->getPackages() as $package) {
            $this->installedMap[$package->getId()] = $package;
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

        foreach ($this->jobs as $job) {
            foreach ($job['packages'] as $package) {
                switch ($job['cmd']) {
                    case 'update':
                        if (isset($this->installedMap[$package->getId()])) {
                            $this->updateMap[$package->getId()] = true;
                        }
                        break;
                }
            }

            switch ($job['cmd']) {
                case 'update-all':
                    foreach ($this->installedMap as $package) {
                        $this->updateMap[$package->getId()] = true;
                    }
                break;
            }
        }

        foreach ($this->installedMap as $package) {
            $this->addRulesForPackage($package);
        }

        foreach ($this->installedMap as $package) {
            $this->addRulesForUpdatePackages($package);
        }


        foreach ($this->jobs as $job) {
            foreach ($job['packages'] as $package) {
                switch ($job['cmd']) {
                    case 'install':
                        $this->installCandidateMap[$package->getId()] = true;
                        $this->addRulesForPackage($package);
                    break;
                }
            }
        }

        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'install':
                    if (empty($job['packages'])) {
                        $problem = new Problem();
                        $problem->addJobRule($job);
                        $this->problems[] = $problem;
                    } else {
                        $rule = $this->createInstallOneOfRule($job['packages'], Rule::RULE_JOB_INSTALL, $job['packageName']);
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                        $this->ruleToJob[$rule->getId()] = $job;
                    }
                    break;
                case 'remove':
                    // remove all packages with this name including uninstalled
                    // ones to make sure none of them are picked as replacements

                    // todo: cleandeps
                    foreach ($job['packages'] as $package) {
                        $rule = $this->createRemoveRule($package, Rule::RULE_JOB_REMOVE);
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                        $this->ruleToJob[$rule->getId()] = $job;
                    }
                    break;
                case 'lock':
                    foreach ($job['packages'] as $package) {
                        if (isset($this->installedMap[$package->getId()])) {
                            $rule = $this->createInstallRule($package, Rule::RULE_JOB_LOCK);
                        } else {
                            $rule = $this->createRemoveRule($package, Rule::RULE_JOB_LOCK);
                        }
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                        $this->ruleToJob[$rule->getId()] = $job;
                    }
                break;
            }
        }

        foreach ($this->rules as $rule) {
            $this->addWatchesToRule($rule);
        }

        /* make decisions based on job/update assertions */
        $this->makeAssertionRuleDecisions();

        $installRecommended = 0;
        $this->runSat(true, $installRecommended);

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

    protected function addDecision(Literal $l, $level)
    {
        $this->addDecisionId($l->getId(), $level);
    }

    protected function addDecisionId($literalId, $level)
    {
        $packageId = abs($literalId);

        $previousDecision = $this->decisionMap[$packageId];
        if ($previousDecision != 0) {
            $literal = $this->literalFromId($literalId);
            throw new SolverBugException(
                "Trying to decide $literal on level $level, even though ".$literal->getPackage()." was previously decided as ".(int) $previousDecision."."
            );
        }

        if ($literalId > 0) {
            $this->decisionMap[$packageId] = $level;
        } else {
            $this->decisionMap[$packageId] = -$level;
        }
    }

    protected function decisionsContain(Literal $l)
    {
        return (
            $this->decisionMap[$l->getPackageId()] > 0 && $l->isWanted() ||
            $this->decisionMap[$l->getPackageId()] < 0 && !$l->isWanted()
        );
    }

    protected function decisionsContainId($literalId)
    {
        $packageId = abs($literalId);
        return (
            $this->decisionMap[$packageId] > 0 && $literalId > 0 ||
            $this->decisionMap[$packageId] < 0 && $literalId < 0
        );
    }

    protected function decisionsSatisfy(Literal $l)
    {
        return ($l->isWanted() && $this->decisionMap[$l->getPackageId()] > 0) ||
            (!$l->isWanted() && $this->decisionMap[$l->getPackageId()] < 0);
    }

    protected function decisionsConflict(Literal $l)
    {
        return (
            $this->decisionMap[$l->getPackageId()] > 0 && !$l->isWanted() ||
            $this->decisionMap[$l->getPackageId()] < 0 && $l->isWanted()
        );
    }

    protected function decisionsConflictId($literalId)
    {
        $packageId = abs($literalId);
        return (
            ($this->decisionMap[$packageId] > 0 && $literalId < 0) ||
            ($this->decisionMap[$packageId] < 0 && $literalId > 0)
        );
    }

    protected function decided(PackageInterface $p)
    {
        return $this->decisionMap[$p->getId()] != 0;
    }

    protected function undecided(PackageInterface $p)
    {
        return $this->decisionMap[$p->getId()] == 0;
    }

    protected function decidedInstall(PackageInterface $p) {
        return $this->decisionMap[$p->getId()] > 0;
    }

    protected function decidedRemove(PackageInterface $p) {
        return $this->decisionMap[$p->getId()] < 0;
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
            // we invert the decided literal here, example:
            // A was decided => (-A|B) now requires B to be true, so we look for
            // rules which are fulfilled by -A, rather than A.

            $literal = $this->decisionQueue[$this->propagateIndex]->inverted();

            $this->propagateIndex++;

            // /* foreach rule where 'pkg' is now FALSE */
            //for (rp = watches + pkg; *rp; rp = next_rp)
            if (!isset($this->watches[$literal->getId()])) {
                continue;
            }

            $prevRule = null;
            for ($rule = $this->watches[$literal->getId()]; $rule !== null; $prevRule = $rule, $rule = $nextRule) {
                $nextRule = $rule->getNext($literal);

                if ($rule->isDisabled()) {
                    continue;
                }

                $otherWatch = $rule->getOtherWatch($literal);

                if ($this->decisionsContainId($otherWatch)) {
                    continue;
                }

                $ruleLiterals = $rule->getLiterals();

                if (sizeof($ruleLiterals) > 2) {
                    foreach ($ruleLiterals as $ruleLiteral) {
                        if ($otherWatch !== $ruleLiteral->getId() &&
                            !$this->decisionsConflict($ruleLiteral)) {

                            if ($literal->getId() === $rule->watch1) {
                                $rule->watch1 = $ruleLiteral->getId();
                                $rule->next1 = (isset($this->watches[$ruleLiteral->getId()])) ? $this->watches[$ruleLiteral->getId()] : null;
                            } else {
                                $rule->watch2 = $ruleLiteral->getId();
                                $rule->next2 = (isset($this->watches[$ruleLiteral->getId()])) ? $this->watches[$ruleLiteral->getId()] : null;
                            }

                            if ($prevRule) {
                                if ($prevRule->next1 == $rule) {
                                    $prevRule->next1 = $nextRule;
                                } else {
                                    $prevRule->next2 = $nextRule;
                                }
                            } else {
                                $this->watches[$literal->getId()] = $nextRule;
                            }

                            $this->watches[$ruleLiteral->getId()] = $rule;

                            $rule = $prevRule;
                            continue 2;
                        }
                    }
                }

                // yay, we found a unit clause! try setting it to true
                if ($this->decisionsConflictId($otherWatch)) {
                    return $rule;
                }

                $this->addDecisionId($otherWatch, $level);

                $this->decisionQueue[] = $this->literalFromId($otherWatch);
                $this->decisionQueueWhy[] = $rule;
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

            if (!$this->decisionMap[$literal->getPackageId()]) {
                break;
            }

            $decisionLevel = abs($this->decisionMap[$literal->getPackageId()]);

            if ($decisionLevel <= $level) {
                break;
            }

            $this->decisionMap[$literal->getPackageId()] = 0;
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

        $this->recommendsIndex = -1;
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
    private function setPropagateLearn($level, Literal $literal, $disableRules, Rule $rule)
    {
        $level++;

        $this->addDecision($literal, $level);
        $this->decisionQueue[] = $literal;
        $this->decisionQueueWhy[] = $rule;
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

            $this->addRule(RuleSet::TYPE_LEARNED, $newRule);

            $this->learnedWhy[$newRule->getId()] = $why;

            $this->watch2OnHighest($newRule);
            $this->addWatchesToRule($newRule);

            $this->addDecision($learnLiteral, $level);
            $this->decisionQueue[] = $learnLiteral;
            $this->decisionQueueWhy[] = $newRule;
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

                if (isset($seen[$literal->getPackageId()])) {
                    continue;
                }
                $seen[$literal->getPackageId()] = true;

                $l = abs($this->decisionMap[$literal->getPackageId()]);

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

                    if (isset($seen[$literal->getPackageId()])) {
                        break;
                    }
                }

                unset($seen[$literal->getPackageId()]);

                if ($num && 0 === --$num) {
                    $learnedLiterals[0] = $this->literalFromId(-$literal->getPackageId());

                    if (!$l1num) {
                        break 2;
                    }

                    foreach ($learnedLiterals as $i => $learnedLiteral) {
                        if ($i !== 0) {
                            unset($seen[$literal->getPackageId()]);
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

        $newRule = new Rule($learnedLiterals, Rule::RULE_LEARNED, $why);

        return array($learnedLiterals[0], $ruleLevel, $newRule, $why);
    }

    private function analyzeUnsolvableRule($problem, $conflictRule, &$lastWeakWhy)
    {
        $why = $conflictRule->getId();

        if ($conflictRule->getType() == RuleSet::TYPE_LEARNED) {
            $learnedWhy = $this->learnedWhy[$why];
            $problemRules = $this->learnedPool[$learnedWhy];

            foreach ($problemRules as $problemRule) {
                $this->analyzeUnsolvableRule($problem, $problemRule, $lastWeakWhy);
            }
            return;
        }

        if ($conflictRule->getType() == RuleSet::TYPE_PACKAGE) {
            // package rules cannot be part of a problem
            return;
        }

        if ($conflictRule->isWeak()) {
            /** TODO why > or < lastWeakWhy? */
            if (!$lastWeakWhy || $why > $lastWeakWhy->getId()) {
                $lastWeakWhy = $conflictRule;
            }
        }

        if ($conflictRule->getType() == RuleSet::TYPE_JOB) {
            $job = $this->ruleToJob[$conflictRule->getId()];
            $problem->addJobRule($job, $conflictRule);
        } else {
            $problem->addRule($conflictRule);
        }
    }

    private function analyzeUnsolvable($conflictRule, $disableRules)
    {
        $lastWeakWhy = null;
        $problem = new Problem;
        $problem->addRule($conflictRule);

        $this->analyzeUnsolvableRule($problem, $conflictRule, $lastWeakWhy);

        $this->problems[] = $problem;

        $seen = array();
        $literals = $conflictRule->getLiterals();

/* unnecessary because unlike rule.d, watch2 == 2nd literal, unless watch2 changed
        if (sizeof($literals) == 2) {
            $literals[1] = $this->literalFromId($conflictRule->watch2);
        }
*/

        foreach ($literals as $literal) {
            // skip the one true literal
            if ($this->decisionsSatisfy($literal)) {
                continue;
            }
            $seen[$literal->getPackageId()] = true;
        }

        $decisionId = count($this->decisionQueue);

        while ($decisionId > 0) {
            $decisionId--;

            $literal = $this->decisionQueue[$decisionId];

            // skip literals that are not in this rule
            if (!isset($seen[$literal->getPackageId()])) {
                continue;
            }

            $why = $this->decisionQueueWhy[$decisionId];
            $problem->addRule($why);

            $this->analyzeUnsolvableRule($problem, $why, $lastWeakWhy);

            $literals = $why->getLiterals();
/* unnecessary because unlike rule.d, watch2 == 2nd literal, unless watch2 changed
            if (sizeof($literals) == 2) {
                $literals[1] = $this->literalFromId($why->watch2);
            }
*/

            foreach ($literals as $literal) {
                // skip the one true literal
                if ($this->decisionsSatisfy($literal)) {
                    continue;
                }
                $seen[$literal->getPackageId()] = true;
            }
        }

        if ($lastWeakWhy) {
            array_pop($this->problems);

            if ($lastWeakWhy->getType() === RuleSet::TYPE_JOB) {
                $why = $this->ruleToJob[$lastWeakWhy];
            } else {
                $why = $lastWeakWhy;
            }

            $this->disableProblem($why);
            $this->resetSolver();

            return 1;
        }

        if ($disableRules) {
            foreach ($this->problems[count($this->problems) - 1] as $reason) {
                if ($reason['job']) {
                    $this->disableProblem($reason['job']);
                } else {
                    $this->disableProblem($reason['rule']);
                }
            }

            $this->resetSolver();
            return 1;
        }

        return 0;
    }

    private function disableProblem($why)
    {
        if ($why instanceof Rule) {
            $why->disable();
        } else if (is_array($why)) {

            // disable all rules of this job
            foreach ($this->ruleToJob as $ruleId => $job) {
                if ($why === $job) {
                    $this->rules->ruleById($ruleId)->disable();
                }
            }
        }
    }

    private function resetSolver()
    {
        while ($literal = array_pop($this->decisionQueue)) {
            $this->decisionMap[$literal->getPackageId()] = 0;
        }

        $this->decisionQueueWhy = array();
        $this->decisionQueueFree = array();
        $this->recommendsIndex = -1;
        $this->propagateIndex = 0;
        $this->recommendations = array();
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

    private function runSat($disableRules = true, $installRecommended = false)
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
                            $decisionQueue[] = $literal;
                        }

                        if ($noneSatisfied && count($decisionQueue)) {
                            // prune all update packages until installed version
                            // except for requested updates
                            if (count($this->installed) != count($this->updateMap)) {
                                $prunedQueue = array();
                                foreach ($decisionQueue as $literal) {
                                    if (isset($this->installedMap[$literal->getPackageId()])) {
                                        $prunedQueue[] = $literal;
                                        if (isset($this->updateMap[$literal->getPackageId()])) {
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
                    if (!$literal->isWanted()) {
                        if (!$this->decidedInstall($literal->getPackage())) {
                            continue 2; // next rule
                        }
                    } else {
                        if ($this->decidedInstall($literal->getPackage())) {
                            continue 2; // next rule
                        }
                        if ($this->undecided($literal->getPackage())) {
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
                        if ($literal && $literal->isWanted() && $this->decisionMap[$literal->getPackageId()] > $level + 1) {
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

    private function printDecisionMap()
    {
        echo "\nDecisionMap: \n";
        foreach ($this->decisionMap as $packageId => $level) {
            if ($packageId === 0) {
                continue;
            }
            if ($level > 0) {
                echo '    +' . $this->pool->packageById($packageId)."\n";
            } elseif ($level < 0) {
                echo '    -' . $this->pool->packageById($packageId)."\n";
            } else {
                echo '    ?' . $this->pool->packageById($packageId)."\n";
            }
        }
        echo "\n";
    }

    private function printDecisionQueue()
    {
        echo "DecisionQueue: \n";
        foreach ($this->decisionQueue as $i => $literal) {
            echo '    ' . $literal . ' ' . $this->decisionQueueWhy[$i]." level ".$this->decisionMap[$literal->getPackageId()]."\n";
        }
        echo "\n";
    }

    private function printWatches()
    {
        echo "\nWatches:\n";
        foreach ($this->watches as $literalId => $watch) {
            echo '  '.$this->literalFromId($literalId)."\n";
            $queue = array(array('    ', $watch));

            while (!empty($queue)) {
                list($indent, $watch) = array_pop($queue);

                echo $indent.$watch;

                if ($watch) {
                    echo ' [id='.$watch->getId().',watch1='.$this->literalFromId($watch->watch1).',watch2='.$this->literalFromId($watch->watch2)."]";
                }

                echo "\n";

                if ($watch && ($watch->next1 == $watch || $watch->next2 == $watch)) {
                    if ($watch->next1 == $watch) {
                        echo $indent."    1 *RECURSION*";
                    }
                    if ($watch->next2 == $watch) {
                        echo $indent."    2 *RECURSION*";
                    }
                } elseif ($watch && ($watch->next1 || $watch->next2)) {
                    $indent = str_replace(array('1', '2'), ' ', $indent);

                    array_push($queue, array($indent.'    2 ', $watch->next2));
                    array_push($queue, array($indent.'    1 ', $watch->next1));
                }
            }

            echo "\n";
        }
    }
}
