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

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Solver
{
    const RULE_INTERNAL_ALLOW_UPDATE = 1;
    const RULE_JOB_INSTALL = 2;
    const RULE_JOB_REMOVE = 3;
    const RULE_JOB_LOCK = 4;
    const RULE_NOT_INSTALLABLE = 5;
    const RULE_NOTHING_PROVIDES_DEP = 6;
    const RULE_PACKAGE_CONFLICT = 7;
    const RULE_PACKAGE_NOT_EXIST = 8;
    const RULE_PACKAGE_REQUIRES = 9;

    const TYPE_PACKAGE = 0;
    const TYPE_FEATURE = 1;
    const TYPE_UPDATE = 2;
    const TYPE_JOB = 3;
    const TYPE_WEAK = 4;
    const TYPE_LEARNED = 5;

    protected $policy;
    protected $pool;
    protected $installed;
    protected $rules;
    protected $updateAll;

    protected $addedMap = array();
    protected $fixMap = array();
    protected $updateMap = array();
    protected $watches = array();
    protected $removeWatches = array();

    public function __construct(PolicyInterface $policy, Pool $pool, RepositoryInterface $installed)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->installed = $installed;
        $this->rules = array(
            // order matters here! further down => higher priority
            self::TYPE_LEARNED => array(),
            self::TYPE_WEAK => array(),
            self::TYPE_FEATURE => array(),
            self::TYPE_UPDATE => array(),
            self::TYPE_JOB => array(),
            self::TYPE_PACKAGE => array(),
        );
    }

    /**
     * Creates a new rule for the requirements of a package
     *
     * This rule is of the form (-A|B|C), where B and C are the providers of
     * one requirement of the package A.
     *
     * @param Package $package    The package with a requirement
     * @param array   $providers  The providers of the requirement
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the requirement name, that goes with
     *                            the reason
     * @return Rule               The generated rule or null if tautological
     */
    public function createRequireRule(Package $package, array $providers, $reason, $reasonData = null)
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
     * Create a new rule for updating a package
     *
     * If package A1 can be updated to A2 or A3 the rule is (A1|A2|A3).
     *
     * @param Package $package    The package to be updated
     * @param array   $updates    An array of update candidate packages
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               The generated rule or null if tautology
     */
    protected function createUpdateRule(Package $package, array $updates, $reason, $reasonData = null)
    {
        $literals = array(new Literal($package, true));

        foreach ($updates as $update) {
            $literals[] = new Literal($update, true);
        }

        return new Rule($literals, $reason, $reasonData);
    }

    /**
     * Creates a new rule for installing a package
     *
     * The rule is simply (A) for a package A to be installed.
     *
     * @param Package $package    The package to be installed
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               The generated rule
     */
    public function createInstallRule(Package $package, $reason, $reasonData = null)
    {
        return new Rule(new Literal($package, true));
    }

    /**
     * Creates a rule to install at least one of a set of packages
     *
     * The rule is (A|B|C) with A, B and C different packages. If the given
     * set of packages is empty an impossible rule is generated.
     *
     * @param array $packages The set of packages to choose from
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               The generated rule
     */
    public function createInstallOneOfRule(array $packages, $reason, $reasonData = null)
    {
        if (empty($packages)) {
            return $this->createImpossibleRule($reason, $reasonData);
        }

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
     * @param Package $package    The package to be removed
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               The generated rule
     */
    public function createRemoveRule(Package $package, $reason, $reasonData = null)
    {
        return new Rule(array(new Literal($package, false)), $reason, $reasonData);
    }

    /**
     * Creates a rule for two conflicting packages
     *
     * The rule for conflicting packages A and B is (-A|-B). A is called the issuer
     * and B the provider.
     *
     * @param Package $issuer   The package declaring the conflict
     * @param Package $provider The package causing the conflict
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               The generated rule
     */
    public function createConflictRule(Package $issuer, Package $provider, $reason, $reasonData = null)
    {
        // ignore self conflict
        if ($issuer === $provider) {
            return null;
        }

        return new Rule(array(new Literal($issuer, false), new Literal($provider, false)), $reason, $reasonData);
    }

    /**
     * Intentionally creates a rule impossible to solve
     *
     * The rule is an empty one so it can never be satisfied.
     *
     * @param int     $reason     A RULE_* constant describing the reason for
     *                            generating this rule
     * @param mixed   $reasonData Any data, e.g. the package name, that goes with
     *                            the reason
     * @return Rule               An empty rule
     */
    public function createImpossibleRule($reason, $reasonData = null)
    {
        return new Rule(array(), $reason, $reasonData);
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
            foreach ($this->rules as $rules) {
                foreach ($rules as $rule) {
                    if ($rule->equals($newRule)) {
                        return;
                    }
                }
            }

            $newRule->setType($type);
            $this->rules[$type][] = $newRule;
        }
    }

    public function addRulesForPackage(Package $package)
    {
        $workQueue = new \SPLQueue;
        $workQueue->enqueue($package);

        while (!$workQueue->isEmpty()) {
            $package = $workQueue->dequeue();
            if (isset($this->addedMap[$package->getId()])) {
                continue;
            }

            $this->addedMap[$package->getId()] = true;

            $dontFix = 0;
            if ($this->installed->contains($package) && !isset($this->fixMap[$package->getId()])) {
                $dontFix = 1;
            }

            if (!$dontFix && !$this->policy->installable($this, $this->pool, $this->installed, $package)) {
                $this->addRule(self::TYPE_PACKAGE, $this->createRemoveRule($package, self::RULE_NOT_INSTALLABLE, (string) $package));
                continue;
            }

            foreach ($package->getRequires() as $relation) {
                $possibleRequires = $this->pool->whatProvides($relation->getToPackageName(), $relation->getConstraint());

                // the strategy here is to not insist on dependencies
                // that are already broken. so if we find one provider
                // that was already installed, we know that the
                // dependency was not broken before so we enforce it
                if ($dontFix) {
                    $foundInstalled = false;
                    foreach ($possibleRequires as $require) {
                        if ($this->installed->contains($require)) {
                            $foundInstalled = true;
                            break;
                        }
                    }

                    // no installed provider found: previously broken dependency => don't add rule
                    if (!$foundInstalled) {
                        continue;
                    }
                }

                $this->addRule(self::TYPE_PACKAGE, $this->createRequireRule($package, $possibleRequires, self::RULE_PACKAGE_REQUIRES, (string) $relation));

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }

            foreach ($package->getConflicts() as $relation) {
                $possibleConflicts = $this->pool->whatProvides($relation->getToPackageName(), $relation->getConstraint());

                foreach ($possibleConflicts as $conflict) {
                    if ($dontfix && $this->installed->contains($conflict)) {
                        continue;
                    }

                    $this->addRule(self::TYPE_PACKAGE, $this->createConflictRule($package, $conflict, self::RULE_PACKAGE_CONFLICT, (string) $relation));
                }
            }

            foreach ($package->getRecommends() as $relation) {
                foreach ($this->pool->whatProvides($relation->getToPackageName(), $relation->getConstraint()) as $recommend) {
                    $workQueue->enqueue($recommend);
                }
            }

            foreach ($package->getSuggests() as $relation) {
                foreach ($this->pool->whatProvides($relation->getToPackageName(), $relation->getConstraint()) as $suggest) {
                    $workQueue->enqueue($suggest);
                }
            }
        }
    }

    /**
     * Adds all rules for all update packages of a given package
     *
     * @param Package $package  Rules for this package's updates are to be added
     * @param bool    $allowAll Whether downgrades are allowed
     */
    private function addRulesForUpdatePackages(Package $package, $allowAll)
    {
        $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, $allowAll);

        $this->addRulesForPackage($package);

        foreach ($updates as $update) {
            $this->addRulesForPackage($update);
        }
    }

    /**
     * Sets up watch chains for all rules.
     *
     * Next1/2 always points to the next rule that is watching the same package.
     * The watches array contains rules to start from for each package
     *
     */
    private function makeWatches()
    {
        foreach ($this->rules as $type => $rules) {
            foreach ($rules as $i => $rule) {

                // skip simple assertions of the form (A) or (-A)
                if ($rule->isAssertion()) {
                    continue;
                }

                if (!isset($this->watches[$rule->watch1])) {
                    $this->watches[$rule->watch1] = 0;
                }

                $rule->next1 = $this->watches[$rule->watch1];
                $this->watches[$rule->watch1] = $rule;

                if (!isset($this->watches[$rule->watch2])) {
                    $this->watches[$rule->watch2] = 0;
                }

                $rule->next2 = $this->watches[$rule->watch2];
                $this->watches[$rule->watch2] = $rule;

            }
        }
    }

    private function findDecisionRule(Package $package)
    {
        foreach ($this->decisionQueue as $i => $literal) {
            if ($package === $literal->getPackage()) {
                return $this->decisionQueueWhy[$i];
            }
        }

        return null;
    }

    private function makeAssertionRuleDecisions()
    {
        // do we need to decide a SYSTEMSOLVABLE at level 1?

        foreach ($this->rules as $type => $rules) {
            if (self::TYPE_WEAK === $type) {
                continue;
            }
            foreach ($rules as $rule) {
                if (!$rule->isAssertion() || $rule->isDisabled()) {
                    continue;
                }

                $literals = $rule->getLiterals();
                $literal = $literals[0];

                if (!$this->decided($literal->getPackage())) {

                }

                if ($this->decisionsSatisfy($literal)) {
                    continue;
                }

                // found a conflict
                if (self::TYPE_LEARNED === $type) {
                    $rule->disable();
                }

                $conflict = $this->findDecisionRule($literal->getPackage());
                // todo: handle conflict with systemsolvable?

                if (self::TYPE_PACKAGE === $conflict->getType()) {

                }
            }
        }

        foreach ($this->rules[self::TYPE_WEAK] as $rule) {
            if (!$rule->isAssertion() || $rule->isDisabled()) {
                continue;
            }

            if ($this->decisionsSatisfy($literals[0])) {
                continue;
            }

            // conflict, but this is a weak rule => disable
            $rule->disable();
        }
    }

    public function solve(Request $request)
    {
        $this->jobs = $request->getJobs();
        $installedPackages = $this->installed->getPackages();

        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'update-all':
                    foreach ($installedPackages as $package) {
                        $this->updateMap[$package->getId()] = true;
                    }
                break;

                case 'fix-all':
                    foreach ($installedPackages as $package) {
                        $this->fixMap[$package->getId()] = true;
                    }
                break;
            }

            foreach ($job['packages'] as $package) {
                switch ($job['cmd']) {
                    case 'fix':
                        if ($this->installed->contains($package)) {
                            $this->fixMap[$package->getId()] = true;
                        }
                        break;
                    case 'update':
                        if ($this->installed->contains($package)) {
                            $this->updateMap[$package->getId()] = true;
                        }
                        break;
                }
            }
        }

        foreach ($installedPackages as $package) {
            $this->addRulesForPackage($package);
        }

        foreach ($installedPackages as $package) {
            $this->addRulesForUpdatePackages($package, true);
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

        // solver_addrpmrulesforweak(solv, &addedmap);
        /*
        * first pass done, we now have all the rpm rules we need.
        * unify existing rules before going over all job rules and
        * policy rules.
        * at this point the system is always solvable,
        * as an empty system (remove all packages) is a valid solution
        */
        // solver_unifyrules(solv);                          /* remove duplicate rpm rules */

// no idea what this is
//   /* create dup maps if needed. We need the maps early to create our
//    * update rules */
//   if (hasdupjob)
//     solver_createdupmaps(solv);

        foreach ($installedPackages as $package) {
            // create a feature rule which allows downgrades
            $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, true);
            $featureRule = $this->createUpdateRule($package, $updates, self::RULE_INTERNAL_ALLOW_UPDATE, (string) $package);

            // create an update rule which does not allow downgrades
            $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, false);
            $rule = $this->createUpdateRule($package, $updates, self::RULE_INTERNAL_ALLOW_UPDATE, (string) $package);

            if ($rule->equals($featureRule)) {
                if ($this->policy->allowUninstall()) {
                    $this->addRule(self::TYPE_WEAK, $featureRule);
                } else {
                    $this->addRule(self::TYPE_UPDATE, $rule);
                }
            } else if ($this->policy->allowUninstall()) {
                $this->addRule(self::TYPE_WEAK, $featureRule);
                $this->addRule(self::TYPE_WEAK, $rule);
            }
        }

        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'install':
                    $rule = $this->createInstallOneOfRule($job['packages'], self::RULE_JOB_INSTALL, $job['packageName']);
                    $this->addRule(self::TYPE_JOB, $rule);
                    //$this->ruleToJob[$rule] = $job;
                    break;
                case 'remove':
                    // remove all packages with this name including uninstalled
                    // ones to make sure none of them are picked as replacements

                    // todo: cleandeps
                    foreach ($job['packages'] as $package) {
                        $rule = $this->createRemoveRule($package, self::RULE_JOB_REMOVE);
                        $this->addRule(self::TYPE_JOB, $rule);
                        //$this->ruleToJob[$rule] = $job;
                    }
                    break;
                case 'lock':
                    foreach ($job['packages'] as $package) {
                        if ($this->installed->contains($package)) {
                            $rule = $this->createInstallRule($package, self::RULE_JOB_LOCK);
                        } else {
                            $rule = $this->createRemoveRule($package, self::RULE_JOB_LOCK);
                        }
                        $this->addRule(self::TYPE_JOB, $rule);
                        //$this->ruleToJob[$rule] = $job;
                    }
                break;
            }
        }

        // solver_addchoicerules(solv);

        $this->makeWatches();

        /* disable update rules that conflict with our job */
        //solver_disablepolicyrules(solv);

        /* make decisions based on job/update assertions */
        $this->makeAssertionRuleDecisions();

        $installRecommended = 0;
        $this->runSat(true, $installRecommended);

        //findrecommendedsuggested(solv);
        //solver_prepare_solutions(solv);
        //transaction_calculate(&solv->trans, &solv->decisionq, &solv->noobsoletes);

    }

    public function printRules()
    {
        print "\n";
        foreach ($this->rules as $type => $rules) {
            print $type . ": ";
            foreach ($rules as $rule) {
                print $rule;
            }
            print "\n";
        }
    }

    protected $decisionQueue = array();
    protected $propagateIndex;
    protected $decisionMap = array();
    protected $branches = array();

    protected function addDecision(Literal $l, $level)
    {
        if ($l->isWanted()) {
            $this->decisionMap[$l->getPackageId()] = $level;
        } else {
            $this->decisionMap[$l->getPackageId()] = -$level;
        }
    }

    protected function decisionsContain(Literal $l)
    {
        return (isset($this->decisionMap[$l->getPackageId()]) && (
            $this->decisionMap[$l->getPackageId()] > 0 && $l->isWanted() ||
            $this->decisionMap[$l->getPackageId()] < 0 && !$l->isWanted()
        ));
    }

    protected function decisionsSatisfy(Literal $l)
    {
        return ($l->isWanted() && isset($this->decisionMap[$l->getPackageId()]) && $this->decisionMap[$l->getPackageId()] > 0) ||
            (!$l->isWanted() && (!isset($this->decisionMap[$l->getPackageId()]) || $this->decisionMap[$l->getPackageId()] < 0));
    }

    protected function decisionsConflict(Literal $l)
    {
        return (isset($this->decisionMap[$l->getPackageId()]) && (
            $this->decisionMap[$l->getPackageId()] > 0 && !$l->isWanted() ||
            $this->decisionMap[$l->getPackageId()] < 0 && $l->isWanted()
        ));
    }

    protected function decided(Package $p)
    {
        return isset($this->decisionMap[$p->getId()]);
    }

    protected function undecided(Package $p)
    {
        return !isset($this->decisionMap[$p->getId()]);
    }

    protected function decidedInstall(Package $p) {
        return isset($this->decisionMap[$p->getId()]) && $this->decisionMap[$p->getId()] > 0;
    }

    protected function decidedRemove(Package $p) {
        return isset($this->decisionMap[$p->getId()]) && $this->decisionMap[$p->getId()] < 0;
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
            for ($rule = $this->watches[$literal->getId()]; $rule !== null; $rule = $nextRule) {
                $nextRule = $rule->getNext($literal);

                if ($rule->isDisabled()) {
                    continue;
                }

                $otherWatch = $rule->getOtherWatch($literal);

                if ($this->decisionsContain($otherWatch)) {
                    continue;
                }

                $ruleLiterals = $rule->getLiterals();

                if (sizeof($ruleLiterals) > 2) {
                    foreach ($ruleLiterals as $ruleLiteral) {
                        if (!$otherWatch->equals($ruleLiteral) &&
                            !$this->decisionsConflict($ruleLiteral)) {


                            if ($literal->equals($rule->getWatch1())) {
                                $rule->setWatch1($ruleLiteral);
                                $rule->setNext1($rule);
                            } else {
                                $rule->setWatch2($ruleLiteral);
                                $rule->setNext2($rule);
                            }

                            $this->watches[$ruleLiteral->getId()] = $rule;
                            continue 2;
                        }
                    }
                }

                // yay, we found a unit clause! try setting it to true
                if ($this->decisionsConflict($otherWatch)) {
                    return $rule;
                }

                $this->addDecision($otherWatch, $level);

                $this->decisionQueue[] = $otherWatch;
                $this->decisionQueueWhy[] = $rule;
            }
        }

        return null;
    }

    private function setPropagateLearn($level, Literal $literal, $disableRules, Rule $rule)
    {
        return 0;
    }

    private function selectAndInstall($level, array $decisionQueue, $disableRules, Rule $rule)
    {
        // choose best package to install from decisionQueue
        $literals = $this->policy->selectPreferedPackages($decisionQueue);

        // if there are multiple candidates, then branch
        if (count($literals) > 1) {
            foreach ($literals as $i => $literal) {
                if (0 !== $i) {
                    $this->branches[] = array($literal, $level);
                }
            }
        }

        return $this->setPropagateLearn($level, $literals[0], $disableRules, $rule);
    }

    private function runSat($disableRules = true, $installRecommended = false)
    {
        $this->propagateIndex = 0;

        //   /*
        //    * here's the main loop:
        //    * 1) propagate new decisions (only needed once)
        //    * 2) fulfill jobs
        //    * 3) try to keep installed packages
        //    * 4) fulfill all unresolved rules
        //    * 5) install recommended packages
        //    * 6) minimalize solution if we had choices
        //    * if we encounter a problem, we rewind to a safe level and restart
        //    * with step 1
        //    */

        $decisionQueue = array();
        $decisionSupplementQueue = array();
        $disableRules = array();

        $level = 1;
        $systemLevel = $level + 1;
        $minimizationsteps = 0;
        $installedPos = 0;

        $this->installedPackages = array_values($this->installed->getPackages());

        while (true) {

            $conflictRule = $this->propagate($level);
            if ($conflictRule !== null) {
//              if (analyze_unsolvable(solv, r, disablerules))
                if ($this->analyzeUnsolvable($conflictRule, $disableRules)) {
                    continue;
                } else {
                    return;
                }
            }

            // handle job rules
            if ($level < $systemLevel) {
                $ruleIndex = 0;
                foreach ($this->rules[self::TYPE_JOB] as $ruleIndex => $rule) {
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
                                    if ($this->installed->contains($literal->getPackage())) {
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
                if ($ruleIndex + 1 < count($this->rules[Solver::TYPE_JOB])) {
                    continue;
                }
            }

            // handle installed packages
            if ($level < $systemLevel) {
                // use two passes if any packages are being updated
                // -> better user experience
                for ($pass = (count($this->updateMap)) ? 0 : 1; $pass < 2; $pass++) {
                    $passLevel = $level;
                    for ($i = $installedPos, $n = 0; $n < count($this->installedPackages); $i++, $n++) {
                        $repeat = false;

                        if ($i == count($this->installedPackages)) {
                            $i = 0;
                        }
                        $literal = new Literal($this->installedPackages[$i], true);

                        if ($this->decisionsContain($literal)) {
                            continue;
                        }

                        // only process updates in first pass
                        if (0 === $pass && !isset($this->updateMap[$literal->getPackageId()])) {
                            continue;
                        }

                        $rule = null;
                        if (isset($this->rules[Solver::TYPE_UPDATE][$literal->getPackageId()])) {
                            $rule = $this->rules[Solver::TYPE_UPDATE][$literal->getPackageId()];
                        }

                        if ((!$rule || $rule->isDisabled()) && isset($this->rules[Solver::TYPE_FEATURE][$literal->getPackageId()])) {
                            $rule = $this->rules[Solver::TYPE_FEATURE][$literal->getPackageId()];
                        }

                        if (!$rule || $rule->isDisabled()) {
                            continue;
                        }

                        $decisionQueue = array();
                        if (!isset($this->noUpdate[$literal->getPackageId()]) && (
                            $this->decidedRemove($literal->getPackage()) ||
                            isset($this->updateMap[$literal->getPackageId()]) ||
                            !$literal->equals($rule->getFirstLiteral())
                        )) {
                            foreach ($rule->getLiterals() as $ruleLiteral) {
                                if ($this->decidedInstall($ruleLiteral->getPackage())) {
                                    // already fulfilled
                                    break;
                                }
                                if ($this->undecided($ruleLiteral->getPackage())) {
                                    $decisionQueue[] = $ruleLiteral;
                                }
                            }
                        }

                        if (sizeof($decisionQueue)) {
                            $oLevel = $level;
                            $level = $this->selectAndInstall($level, $decisionQueue, $disableRules, $rule);

                            if (0 === $level) {
                                return;
                            }

                            if ($level <= $oLevel) {
                                $repeat = true;
                            }
                        }

                        // still undecided? keep package.
                        if (!$repeat && $this->undecided($literal->getPackage())) {
                            $oLevel = $level;
                            if (isset($this->cleanDepsMap[$literal->getPackageId()])) {
                                // clean deps removes package
                                $level = $this->setPropagateLearn($level, $literal->invert(), $disableRules, null);
                            } else {
                                // ckeeping package
                                $level = $this->setPropagateLearn($level, $literal, $disableRules, $rule);
                            }


                            if (0 === $level) {
                                return;
                            }

                            if ($level <= $oLevel) {
                                $repeat = true;
                            }
                        }

                        if ($repeat) {
                            if (1 === $level || $level < $passLevel) {
                                // trouble
                                break;
                            }
                            if ($level < $oLevel) {
                                // redo all
                                $n = 0;
                            }

                            // repeat
                            $i--;
                            $n--;
                            continue;
                        }
                    }

                    if ($n < count($this->installedPackages)) {
                        $installedPos = $i; // retry this problem next time
                        break;
                    }

                    $installedPos = 0;
                }

                $systemlevel = $level + 1;

                if ($pass < 2) {
                    // had trouble => retry
                    continue;
                }
            }

            if ($level < $systemLevel) {
                $systemLevel = $level;
            }

            foreach ($this->rules as $ruleType => $rules) {
                foreach ($rules as $rule) {
                    if ($rule->isEnabled()) {
                        $decisionQueue = array();
                    }
                }
            }
$this->printRules();
//
//       /*
//        * decide
//        */
//       POOL_DEBUG(SAT_DEBUG_POLICY, "deciding unresolved rules\n");
//       for (i = 1, n = 1; n < solv->nrules; i++, n++)
//     {
//       if (i == solv->nrules)
//         i = 1;
//       r = solv->rules + i;
//       if (r->d < 0)     /* ignore disabled rules */
//         continue;
//       queue_empty(&dq);
//       if (r->d == 0)
//         {
//           /* binary or unary rule */
//           /* need two positive undecided literals */
//           if (r->p < 0 || r->w2 <= 0)
//         continue;
//           if (solv->decisionmap[r->p] || solv->decisionmap[r->w2])
//         continue;
//           queue_push(&dq, r->p);
//           queue_push(&dq, r->w2);
//         }
//       else
//         {
//           /* make sure that
//                * all negative literals are installed
//                * no positive literal is installed
//            * i.e. the rule is not fulfilled and we
//                * just need to decide on the positive literals
//                */
//           if (r->p < 0)
//         {
//           if (solv->decisionmap[-r->p] <= 0)
//             continue;
//         }
//           else
//         {
//           if (solv->decisionmap[r->p] > 0)
//             continue;
//           if (solv->decisionmap[r->p] == 0)
//             queue_push(&dq, r->p);
//         }
//           dp = pool->whatprovidesdata + r->d;
//           while ((p = *dp++) != 0)
//         {
//           if (p < 0)
//             {
//               if (solv->decisionmap[-p] <= 0)
//             break;
//             }
//           else
//             {
//               if (solv->decisionmap[p] > 0)
//             break;
//               if (solv->decisionmap[p] == 0)
//             queue_push(&dq, p);
//             }
//         }
//           if (p)
//         continue;
//         }
//       IF_POOLDEBUG (SAT_DEBUG_PROPAGATE)
//         {
//           POOL_DEBUG(SAT_DEBUG_PROPAGATE, "unfulfilled ");
//           solver_printruleclass(solv, SAT_DEBUG_PROPAGATE, r);
//         }
//       /* dq.count < 2 cannot happen as this means that
//        * the rule is unit */
//       assert(dq.count > 1);
//
//       olevel = level;
//       level = selectandinstall(solv, level, &dq, disablerules, r - solv->rules);
//       if (level == 0)
//         {
//           queue_free(&dq);
//           queue_free(&dqs);
//           return;
//         }
//       if (level < systemlevel || level == 1)
//         break;      /* trouble */
//       /* something changed, so look at all rules again */
//       n = 0;
//     }
//
//       if (n != solv->nrules)    /* ran into trouble, restart */
//     continue;
//
//       /* at this point we have a consistent system. now do the extras... */
//
        }
    }

// void
// solver_run_sat(Solver *solv, int disablerules, int doweak)
// {
//   Queue dq;     /* local decisionqueue */
//   Queue dqs;        /* local decisionqueue for supplements */
//   int systemlevel;
//   int level, olevel;
//   Rule *r;
//   int i, j, n;
//   Solvable *s;
//   Pool *pool = solv->pool;
//   Id p, *dp;
//   int minimizationsteps;
//   int installedpos = solv->installed ? solv->installed->start : 0;
//
//   IF_POOLDEBUG (SAT_DEBUG_RULE_CREATION)
//     {
//       POOL_DEBUG (SAT_DEBUG_RULE_CREATION, "number of rules: %d\n", solv->nrules);
//       for (i = 1; i < solv->nrules; i++)
//     solver_printruleclass(solv, SAT_DEBUG_RULE_CREATION, solv->rules + i);
//     }
//
//   POOL_DEBUG(SAT_DEBUG_SOLVER, "initial decisions: %d\n", solv->decisionq.count);
//
//   IF_POOLDEBUG (SAT_DEBUG_SCHUBI)
//     solver_printdecisions(solv);
//
//   /* start SAT algorithm */
//   level = 1;
//   systemlevel = level + 1;
//   POOL_DEBUG(SAT_DEBUG_SOLVER, "solving...\n");
//
//   queue_init(&dq);
//   queue_init(&dqs);
//
//   /*
//    * here's the main loop:
//    * 1) propagate new decisions (only needed once)
//    * 2) fulfill jobs
//    * 3) try to keep installed packages
//    * 4) fulfill all unresolved rules
//    * 5) install recommended packages
//    * 6) minimalize solution if we had choices
//    * if we encounter a problem, we rewind to a safe level and restart
//    * with step 1
//    */
//
//   minimizationsteps = 0;
//   for (;;)
//     {
//       /*
//        * initial propagation of the assertions
//        */
//       if (level == 1)
//     {
//       POOL_DEBUG(SAT_DEBUG_PROPAGATE, "propagating (propagate_index: %d;  size decisionq: %d)...\n", solv->propagate_index, solv->decisionq.count);
//       if ((r = propagate(solv, level)) != 0)
//         {
//           if (analyze_unsolvable(solv, r, disablerules))
//         continue;
//           queue_free(&dq);
//           queue_free(&dqs);
//           return;
//         }
//     }
//
//       /*
//        * resolve jobs first
//        */
//      if (level < systemlevel)
//     {
//       POOL_DEBUG(SAT_DEBUG_SOLVER, "resolving job rules\n");
//       for (i = solv->jobrules, r = solv->rules + i; i < solv->jobrules_end; i++, r++)
//         {
//           Id l;
//           if (r->d < 0)     /* ignore disabled rules */
//         continue;
//           queue_empty(&dq);
//           FOR_RULELITERALS(l, dp, r)
//         {
//           if (l < 0)
//             {
//               if (solv->decisionmap[-l] <= 0)
//             break;
//             }
//           else
//             {
//               if (solv->decisionmap[l] > 0)
//             break;
//               if (solv->decisionmap[l] == 0)
//             queue_push(&dq, l);
//             }
//         }
//           if (l || !dq.count)
//         continue;
//           /* prune to installed if not updating */
//           if (dq.count > 1 && solv->installed && !solv->updatemap_all)
//         {
//           int j, k;
//           for (j = k = 0; j < dq.count; j++)
//             {
//               Solvable *s = pool->solvables + dq.elements[j];
//               if (s->repo == solv->installed)
//             {
//               dq.elements[k++] = dq.elements[j];
//               if (solv->updatemap.size && MAPTST(&solv->updatemap, dq.elements[j] - solv->installed->start))
//                 {
//                   k = 0;    /* package wants to be updated, do not prune */
//                   break;
//                 }
//             }
//             }
//           if (k)
//             dq.count = k;
//         }
//           olevel = level;
//           level = selectandinstall(solv, level, &dq, disablerules, i);
//           if (level == 0)
//         {
//           queue_free(&dq);
//           queue_free(&dqs);
//           return;
//         }
//           if (level <= olevel)
//         break;
//         }
//       systemlevel = level + 1;
//       if (i < solv->jobrules_end)
//         continue;
//     }
//
//
//       /*
//        * installed packages
//        */
//       if (level < systemlevel && solv->installed && solv->installed->nsolvables && !solv->installed->disabled)
//     {
//       Repo *installed = solv->installed;
//       int pass;
//
//       POOL_DEBUG(SAT_DEBUG_SOLVER, "resolving installed packages\n");
//       /* we use two passes if we need to update packages
//            * to create a better user experience */
//       for (pass = solv->updatemap.size ? 0 : 1; pass < 2; pass++)
//         {
//           int passlevel = level;
//           /* start with installedpos, the position that gave us problems last time */
//           for (i = installedpos, n = installed->start; n < installed->end; i++, n++)
//         {
//           Rule *rr;
//           Id d;
//
//           if (i == installed->end)
//             i = installed->start;
//           s = pool->solvables + i;
//           if (s->repo != installed)
//             continue;
//
//           if (solv->decisionmap[i] > 0)
//             continue;
//           if (!pass && solv->updatemap.size && !MAPTST(&solv->updatemap, i - installed->start))
//             continue;       /* updates first */
//           r = solv->rules + solv->updaterules + (i - installed->start);
//           rr = r;
//           if (!rr->p || rr->d < 0)  /* disabled -> look at feature rule */
//             rr -= solv->installed->end - solv->installed->start;
//           if (!rr->p)       /* identical to update rule? */
//             rr = r;
//           if (!rr->p)
//             continue;       /* orpaned package */
//
//           /* XXX: noupdate check is probably no longer needed, as all jobs should
//            * already be satisfied */
//           /* Actually we currently still need it because of erase jobs */
//           /* if noupdate is set we do not look at update candidates */
//           queue_empty(&dq);
//           if (!MAPTST(&solv->noupdate, i - installed->start) && (solv->decisionmap[i] < 0 || solv->updatemap_all || (solv->updatemap.size && MAPTST(&solv->updatemap, i - installed->start)) || rr->p != i))
//             {
//               if (solv->noobsoletes.size && solv->multiversionupdaters
//                  && (d = solv->multiversionupdaters[i - installed->start]) != 0)
//             {
//               /* special multiversion handling, make sure best version is chosen */
//               queue_push(&dq, i);
//               while ((p = pool->whatprovidesdata[d++]) != 0)
//                 if (solv->decisionmap[p] >= 0)
//                   queue_push(&dq, p);
//               policy_filter_unwanted(solv, &dq, POLICY_MODE_CHOOSE);
//               p = dq.elements[0];
//               if (p != i && solv->decisionmap[p] == 0)
//                 {
//                   rr = solv->rules + solv->featurerules + (i - solv->installed->start);
//                   if (!rr->p)       /* update rule == feature rule? */
//                 rr = rr - solv->featurerules + solv->updaterules;
//                   dq.count = 1;
//                 }
//               else
//                 dq.count = 0;
//             }
//               else
//             {
//               /* update to best package */
//               FOR_RULELITERALS(p, dp, rr)
//                 {
//                   if (solv->decisionmap[p] > 0)
//                 {
//                   dq.count = 0;     /* already fulfilled */
//                   break;
//                 }
//                   if (!solv->decisionmap[p])
//                 queue_push(&dq, p);
//                 }
//             }
//             }
//           /* install best version */
//           if (dq.count)
//             {
//               olevel = level;
//               level = selectandinstall(solv, level, &dq, disablerules, rr - solv->rules);
//               if (level == 0)
//             {
//               queue_free(&dq);
//               queue_free(&dqs);
//               return;
//             }
//               if (level <= olevel)
//             {
//               if (level == 1 || level < passlevel)
//                 break;  /* trouble */
//               if (level < olevel)
//                 n = installed->start;   /* redo all */
//               i--;
//               n--;
//               continue;
//             }
//             }
//           /* if still undecided keep package */
//           if (solv->decisionmap[i] == 0)
//             {
//               olevel = level;
//               if (solv->cleandepsmap.size && MAPTST(&solv->cleandepsmap, i - installed->start))
//             {
//               POOL_DEBUG(SAT_DEBUG_POLICY, "cleandeps erasing %s\n", solvid2str(pool, i));
//               level = setpropagatelearn(solv, level, -i, disablerules, 0);
//             }
//               else
//             {
//               POOL_DEBUG(SAT_DEBUG_POLICY, "keeping %s\n", solvid2str(pool, i));
//               level = setpropagatelearn(solv, level, i, disablerules, r - solv->rules);
//             }
//               if (level == 0)
//             {
//               queue_free(&dq);
//               queue_free(&dqs);
//               return;
//             }
//               if (level <= olevel)
//             {
//               if (level == 1 || level < passlevel)
//                 break;  /* trouble */
//               if (level < olevel)
//                 n = installed->start;   /* redo all */
//               i--;
//               n--;
//               continue; /* retry with learnt rule */
//             }
//             }
//         }
//           if (n < installed->end)
//         {
//           installedpos = i; /* retry problem solvable next time */
//           break;        /* ran into trouble */
//         }
//           installedpos = installed->start;  /* reset installedpos */
//         }
//       systemlevel = level + 1;
//       if (pass < 2)
//         continue;       /* had trouble, retry */
//     }
//
//       if (level < systemlevel)
//         systemlevel = level;
//
//       /*
//        * decide
//        */
//       POOL_DEBUG(SAT_DEBUG_POLICY, "deciding unresolved rules\n");
//       for (i = 1, n = 1; n < solv->nrules; i++, n++)
//     {
//       if (i == solv->nrules)
//         i = 1;
//       r = solv->rules + i;
//       if (r->d < 0)     /* ignore disabled rules */
//         continue;
//       queue_empty(&dq);
//       if (r->d == 0)
//         {
//           /* binary or unary rule */
//           /* need two positive undecided literals */
//           if (r->p < 0 || r->w2 <= 0)
//         continue;
//           if (solv->decisionmap[r->p] || solv->decisionmap[r->w2])
//         continue;
//           queue_push(&dq, r->p);
//           queue_push(&dq, r->w2);
//         }
//       else
//         {
//           /* make sure that
//                * all negative literals are installed
//                * no positive literal is installed
//            * i.e. the rule is not fulfilled and we
//                * just need to decide on the positive literals
//                */
//           if (r->p < 0)
//         {
//           if (solv->decisionmap[-r->p] <= 0)
//             continue;
//         }
//           else
//         {
//           if (solv->decisionmap[r->p] > 0)
//             continue;
//           if (solv->decisionmap[r->p] == 0)
//             queue_push(&dq, r->p);
//         }
//           dp = pool->whatprovidesdata + r->d;
//           while ((p = *dp++) != 0)
//         {
//           if (p < 0)
//             {
//               if (solv->decisionmap[-p] <= 0)
//             break;
//             }
//           else
//             {
//               if (solv->decisionmap[p] > 0)
//             break;
//               if (solv->decisionmap[p] == 0)
//             queue_push(&dq, p);
//             }
//         }
//           if (p)
//         continue;
//         }
//       IF_POOLDEBUG (SAT_DEBUG_PROPAGATE)
//         {
//           POOL_DEBUG(SAT_DEBUG_PROPAGATE, "unfulfilled ");
//           solver_printruleclass(solv, SAT_DEBUG_PROPAGATE, r);
//         }
//       /* dq.count < 2 cannot happen as this means that
//        * the rule is unit */
//       assert(dq.count > 1);
//
//       olevel = level;
//       level = selectandinstall(solv, level, &dq, disablerules, r - solv->rules);
//       if (level == 0)
//         {
//           queue_free(&dq);
//           queue_free(&dqs);
//           return;
//         }
//       if (level < systemlevel || level == 1)
//         break;      /* trouble */
//       /* something changed, so look at all rules again */
//       n = 0;
//     }
//
//       if (n != solv->nrules)    /* ran into trouble, restart */
//     continue;
//
//       /* at this point we have a consistent system. now do the extras... */
//
//       if (doweak)
//     {
//       int qcount;
//
//       POOL_DEBUG(SAT_DEBUG_POLICY, "installing recommended packages\n");
//       queue_empty(&dq); /* recommended packages */
//       queue_empty(&dqs);    /* supplemented packages */
//       for (i = 1; i < pool->nsolvables; i++)
//         {
//           if (solv->decisionmap[i] < 0)
//         continue;
//           if (solv->decisionmap[i] > 0)
//         {
//           /* installed, check for recommends */
//           Id *recp, rec, pp, p;
//           s = pool->solvables + i;
//           if (solv->ignorealreadyrecommended && s->repo == solv->installed)
//             continue;
//           /* XXX need to special case AND ? */
//           if (s->recommends)
//             {
//               recp = s->repo->idarraydata + s->recommends;
//               while ((rec = *recp++) != 0)
//             {
//               qcount = dq.count;
//               FOR_PROVIDES(p, pp, rec)
//                 {
//                   if (solv->decisionmap[p] > 0)
//                 {
//                   dq.count = qcount;
//                   break;
//                 }
//                   else if (solv->decisionmap[p] == 0)
//                 {
//                   queue_pushunique(&dq, p);
//                 }
//                 }
//             }
//             }
//         }
//           else
//         {
//           s = pool->solvables + i;
//           if (!s->supplements)
//             continue;
//           if (!pool_installable(pool, s))
//             continue;
//           if (!solver_is_supplementing(solv, s))
//             continue;
//           queue_push(&dqs, i);
//         }
//         }
//
//       /* filter out all packages obsoleted by installed packages */
//       /* this is no longer needed if we have reverse obsoletes */
//           if ((dqs.count || dq.count) && solv->installed)
//         {
//           Map obsmap;
//           Id obs, *obsp, po, ppo;
//
//           map_init(&obsmap, pool->nsolvables);
//           for (p = solv->installed->start; p < solv->installed->end; p++)
//         {
//           s = pool->solvables + p;
//           if (s->repo != solv->installed || !s->obsoletes)
//             continue;
//           if (solv->decisionmap[p] <= 0)
//             continue;
//           if (solv->noobsoletes.size && MAPTST(&solv->noobsoletes, p))
//             continue;
//           obsp = s->repo->idarraydata + s->obsoletes;
//           /* foreach obsoletes */
//           while ((obs = *obsp++) != 0)
//             FOR_PROVIDES(po, ppo, obs)
//               MAPSET(&obsmap, po);
//         }
//           for (i = j = 0; i < dqs.count; i++)
//         if (!MAPTST(&obsmap, dqs.elements[i]))
//           dqs.elements[j++] = dqs.elements[i];
//           dqs.count = j;
//           for (i = j = 0; i < dq.count; i++)
//         if (!MAPTST(&obsmap, dq.elements[i]))
//           dq.elements[j++] = dq.elements[i];
//           dq.count = j;
//           map_free(&obsmap);
//         }
//
//           /* filter out all already supplemented packages if requested */
//           if (solv->ignorealreadyrecommended && dqs.count)
//         {
//           /* turn off all new packages */
//           for (i = 0; i < solv->decisionq.count; i++)
//         {
//           p = solv->decisionq.elements[i];
//           if (p < 0)
//             continue;
//           s = pool->solvables + p;
//           if (s->repo && s->repo != solv->installed)
//             solv->decisionmap[p] = -solv->decisionmap[p];
//         }
//           /* filter out old supplements */
//           for (i = j = 0; i < dqs.count; i++)
//         {
//           p = dqs.elements[i];
//           s = pool->solvables + p;
//           if (!s->supplements)
//             continue;
//           if (!solver_is_supplementing(solv, s))
//             dqs.elements[j++] = p;
//         }
//           dqs.count = j;
//           /* undo turning off */
//           for (i = 0; i < solv->decisionq.count; i++)
//         {
//           p = solv->decisionq.elements[i];
//           if (p < 0)
//             continue;
//           s = pool->solvables + p;
//           if (s->repo && s->repo != solv->installed)
//             solv->decisionmap[p] = -solv->decisionmap[p];
//         }
//         }
//
//       /* multiversion doesn't mix well with supplements.
//        * filter supplemented packages where we already decided
//        * to install a different version (see bnc#501088) */
//           if (dqs.count && solv->noobsoletes.size)
//         {
//           for (i = j = 0; i < dqs.count; i++)
//         {
//           p = dqs.elements[i];
//           if (MAPTST(&solv->noobsoletes, p))
//             {
//               Id p2, pp2;
//               s = pool->solvables + p;
//               FOR_PROVIDES(p2, pp2, s->name)
//             if (solv->decisionmap[p2] > 0 && pool->solvables[p2].name == s->name)
//               break;
//               if (p2)
//             continue;   /* ignore this package */
//             }
//           dqs.elements[j++] = p;
//         }
//           dqs.count = j;
//         }
//
//           /* make dq contain both recommended and supplemented pkgs */
//       if (dqs.count)
//         {
//           for (i = 0; i < dqs.count; i++)
//         queue_pushunique(&dq, dqs.elements[i]);
//         }
//
//       if (dq.count)
//         {
//           Map dqmap;
//           int decisioncount = solv->decisionq.count;
//
//           if (dq.count == 1)
//         {
//           /* simple case, just one package. no need to choose  */
//           p = dq.elements[0];
//           if (dqs.count)
//             POOL_DEBUG(SAT_DEBUG_POLICY, "installing supplemented %s\n", solvid2str(pool, p));
//           else
//             POOL_DEBUG(SAT_DEBUG_POLICY, "installing recommended %s\n", solvid2str(pool, p));
//           queue_push(&solv->recommendations, p);
//           level = setpropagatelearn(solv, level, p, 0, 0);
//           continue; /* back to main loop */
//         }
//
//           /* filter packages, this gives us the best versions */
//           policy_filter_unwanted(solv, &dq, POLICY_MODE_RECOMMEND);
//
//           /* create map of result */
//           map_init(&dqmap, pool->nsolvables);
//           for (i = 0; i < dq.count; i++)
//         MAPSET(&dqmap, dq.elements[i]);
//
//           /* install all supplemented packages */
//           for (i = 0; i < dqs.count; i++)
//         {
//           p = dqs.elements[i];
//           if (solv->decisionmap[p] || !MAPTST(&dqmap, p))
//             continue;
//           POOL_DEBUG(SAT_DEBUG_POLICY, "installing supplemented %s\n", solvid2str(pool, p));
//           queue_push(&solv->recommendations, p);
//           olevel = level;
//           level = setpropagatelearn(solv, level, p, 0, 0);
//           if (level <= olevel)
//             break;
//         }
//           if (i < dqs.count || solv->decisionq.count < decisioncount)
//         {
//           map_free(&dqmap);
//           continue;
//         }
//
//           /* install all recommended packages */
//           /* more work as we want to created branches if multiple
//                * choices are valid */
//           for (i = 0; i < decisioncount; i++)
//         {
//           Id rec, *recp, pp;
//           p = solv->decisionq.elements[i];
//           if (p < 0)
//             continue;
//           s = pool->solvables + p;
//           if (!s->repo || (solv->ignorealreadyrecommended && s->repo == solv->installed))
//             continue;
//           if (!s->recommends)
//             continue;
//           recp = s->repo->idarraydata + s->recommends;
//           while ((rec = *recp++) != 0)
//             {
//               queue_empty(&dq);
//               FOR_PROVIDES(p, pp, rec)
//             {
//               if (solv->decisionmap[p] > 0)
//                 {
//                   dq.count = 0;
//                   break;
//                 }
//               else if (solv->decisionmap[p] == 0 && MAPTST(&dqmap, p))
//                 queue_pushunique(&dq, p);
//             }
//               if (!dq.count)
//             continue;
//               if (dq.count > 1)
//             {
//               /* multiple candidates, open a branch */
//               for (i = 1; i < dq.count; i++)
//                 queue_push(&solv->branches, dq.elements[i]);
//               queue_push(&solv->branches, -level);
//             }
//               p = dq.elements[0];
//               POOL_DEBUG(SAT_DEBUG_POLICY, "installing recommended %s\n", solvid2str(pool, p));
//               queue_push(&solv->recommendations, p);
//               olevel = level;
//               level = setpropagatelearn(solv, level, p, 0, 0);
//               if (level <= olevel || solv->decisionq.count < decisioncount)
//             break;  /* we had to revert some decisions */
//             }
//           if (rec)
//             break;  /* had a problem above, quit loop */
//         }
//           map_free(&dqmap);
//
//           continue;     /* back to main loop so that all deps are checked */
//         }
//     }
//
//      if (solv->dupmap_all && solv->installed)
//     {
//       int installedone = 0;
//
//       /* let's see if we can install some unsupported package */
//       POOL_DEBUG(SAT_DEBUG_SOLVER, "deciding orphaned packages\n");
//       for (i = 0; i < solv->orphaned.count; i++)
//         {
//           p = solv->orphaned.elements[i];
//           if (solv->decisionmap[p])
//         continue;   /* already decided */
//           olevel = level;
//           if (solv->droporphanedmap_all)
//         continue;
//           if (solv->droporphanedmap.size && MAPTST(&solv->droporphanedmap, p - solv->installed->start))
//         continue;
//           POOL_DEBUG(SAT_DEBUG_SOLVER, "keeping orphaned %s\n", solvid2str(pool, p));
//           level = setpropagatelearn(solv, level, p, 0, 0);
//           installedone = 1;
//           if (level < olevel)
//         break;
//         }
//       if (installedone || i < solv->orphaned.count)
//         continue;       /* back to main loop */
//       for (i = 0; i < solv->orphaned.count; i++)
//         {
//           p = solv->orphaned.elements[i];
//           if (solv->decisionmap[p])
//         continue;   /* already decided */
//           POOL_DEBUG(SAT_DEBUG_SOLVER, "removing orphaned %s\n", solvid2str(pool, p));
//           olevel = level;
//           level = setpropagatelearn(solv, level, -p, 0, 0);
//           if (level < olevel)
//         break;
//         }
//       if (i < solv->orphaned.count)
//         continue;       /* back to main loop */
//     }
//
//      if (solv->solution_callback)
//     {
//       solv->solution_callback(solv, solv->solution_callback_data);
//       if (solv->branches.count)
//         {
//           int i = solv->branches.count - 1;
//           int l = -solv->branches.elements[i];
//           Id why;
//
//           for (; i > 0; i--)
//         if (solv->branches.elements[i - 1] < 0)
//           break;
//           p = solv->branches.elements[i];
//           POOL_DEBUG(SAT_DEBUG_SOLVER, "branching with %s\n", solvid2str(pool, p));
//           queue_empty(&dq);
//           for (j = i + 1; j < solv->branches.count; j++)
//         queue_push(&dq, solv->branches.elements[j]);
//           solv->branches.count = i;
//           level = l;
//           revert(solv, level);
//           if (dq.count > 1)
//             for (j = 0; j < dq.count; j++)
//           queue_push(&solv->branches, dq.elements[j]);
//           olevel = level;
//           why = -solv->decisionq_why.elements[solv->decisionq_why.count];
//           assert(why >= 0);
//           level = setpropagatelearn(solv, level, p, disablerules, why);
//           if (level == 0)
//         {
//           queue_free(&dq);
//           queue_free(&dqs);
//           return;
//         }
//           continue;
//         }
//       /* all branches done, we're finally finished */
//       break;
//     }
//
//       /* minimization step */
//      if (solv->branches.count)
//     {
//       int l = 0, lasti = -1, lastl = -1;
//       Id why;
//
//       p = 0;
//       for (i = solv->branches.count - 1; i >= 0; i--)
//         {
//           p = solv->branches.elements[i];
//           if (p < 0)
//         l = -p;
//           else if (p > 0 && solv->decisionmap[p] > l + 1)
//         {
//           lasti = i;
//           lastl = l;
//         }
//         }
//       if (lasti >= 0)
//         {
//           /* kill old solvable so that we do not loop */
//           p = solv->branches.elements[lasti];
//           solv->branches.elements[lasti] = 0;
//           POOL_DEBUG(SAT_DEBUG_SOLVER, "minimizing %d -> %d with %s\n", solv->decisionmap[p], lastl, solvid2str(pool, p));
//           minimizationsteps++;
//
//           level = lastl;
//           revert(solv, level);
//           why = -solv->decisionq_why.elements[solv->decisionq_why.count];
//           assert(why >= 0);
//           olevel = level;
//           level = setpropagatelearn(solv, level, p, disablerules, why);
//           if (level == 0)
//         {
//           queue_free(&dq);
//           queue_free(&dqs);
//           return;
//         }
//           continue;     /* back to main loop */
//         }
//     }
//       /* no minimization found, we're finally finished! */
//       break;
//     }
//
//   POOL_DEBUG(SAT_DEBUG_STATS, "solver statistics: %d learned rules, %d unsolvable, %d minimization steps\n", solv->stats_learned, solv->stats_unsolvable, minimizationsteps);
//
//   POOL_DEBUG(SAT_DEBUG_STATS, "done solving.\n\n");
//   queue_free(&dq);
//   queue_free(&dqs);
// #if 0
//   solver_printdecisionq(solv, SAT_DEBUG_RESULT);
// #endif
// }
}
