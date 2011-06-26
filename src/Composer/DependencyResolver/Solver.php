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
    public function createRequireRule(PackageInterface $package, array $providers, $reason, $reasonData = null)
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
     * @param PackageInterface $package    The package to be updated
     * @param array            $updates    An array of update candidate packages
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule or null if tautology
     */
    protected function createUpdateRule(PackageInterface $package, array $updates, $reason, $reasonData = null)
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
     * @param PackageInterface $package    The package to be installed
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    public function createInstallRule(PackageInterface $package, $reason, $reasonData = null)
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
     * @param PackageInterface $package    The package to be removed
     * @param int              $reason     A RULE_* constant describing the
     *                                     reason for generating this rule
     * @param mixed            $reasonData Any data, e.g. the package name, that
     *                                     goes with the reason
     * @return Rule                        The generated rule
     */
    public function createRemoveRule(PackageInterface $package, $reason, $reasonData = null)
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
    public function createConflictRule(PackageInterface $issuer, Package $provider, $reason, $reasonData = null)
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
            foreach ($this->rules->getIterator() as $rule) {
                if ($rule->equals($newRule)) {
                    return;
                }
            }

            $this->rules->add($newRule, $type);
        }
    }

    public function addRulesForPackage(PackageInterface $package)
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
            if ($this->installed === $package->getRepository() && !isset($this->fixMap[$package->getId()])) {
                $dontFix = 1;
            }

            if (!$dontFix && !$this->policy->installable($this, $this->pool, $this->installed, $package)) {
                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRemoveRule($package, self::RULE_NOT_INSTALLABLE, (string) $package));
                continue;
            }

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                // the strategy here is to not insist on dependencies
                // that are already broken. so if we find one provider
                // that was already installed, we know that the
                // dependency was not broken before so we enforce it
                if ($dontFix) {
                    $foundInstalled = false;
                    foreach ($possibleRequires as $require) {
                        if ($this->installed === $require->getRepository()) {
                            $foundInstalled = true;
                            break;
                        }
                    }

                    // no installed provider found: previously broken dependency => don't add rule
                    if (!$foundInstalled) {
                        continue;
                    }
                }

                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, $possibleRequires, self::RULE_PACKAGE_REQUIRES, (string) $link));

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }

            foreach ($package->getConflicts() as $link) {
                $possibleConflicts = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                foreach ($possibleConflicts as $conflict) {
                    if ($dontfix && $this->installed === $conflict->getRepository()) {
                        continue;
                    }

                    $this->addRule(RuleSet::TYPE_PACKAGE, $this->createConflictRule($package, $conflict, self::RULE_PACKAGE_CONFLICT, (string) $link));
                }
            }

            foreach ($package->getRecommends() as $link) {
                foreach ($this->pool->whatProvides($link->getTarget(), $link->getConstraint()) as $recommend) {
                    $workQueue->enqueue($recommend);
                }
            }

            foreach ($package->getSuggests() as $link) {
                foreach ($this->pool->whatProvides($link->getTarget(), $link->getConstraint()) as $suggest) {
                    $workQueue->enqueue($suggest);
                }
            }
        }
    }

    /**
     * Adds all rules for all update packages of a given package
     *
     * @param PackageInterface $package  Rules for this package's updates are to
     *                                   be added
     * @param bool             $allowAll Whether downgrades are allowed
     */
    private function addRulesForUpdatePackages(PackageInterface $package, $allowAll)
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
        foreach ($this->rules as $rule) {
            // skip simple assertions of the form (A) or (-A)
            if ($rule->isAssertion()) {
                continue;
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

    private function makeAssertionRuleDecisions()
    {
        // do we need to decide a SYSTEMSOLVABLE at level 1?

        foreach ($this->rules->getIteratorWithout(RuleSet::TYPE_WEAK) as $rule) {
            if (!$rule->isAssertion() || $rule->isDisabled()) {
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
            }

            $conflict = $this->findDecisionRule($literal->getPackage());
            // todo: handle conflict with systemsolvable?

            if ($conflict && RuleSet::TYPE_PACKAGE === $conflict->getType()) {

            }
        }

        foreach ($this->rules->getIteratorFor(RuleSet::TYPE_WEAK) as $rule) {
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
                        if ($this->installed === $package->getRepository()) {
                            $this->fixMap[$package->getId()] = true;
                        }
                        break;
                    case 'update':
                        if ($this->installed === $package->getRepository()) {
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

        foreach ($installedPackages as $package) {
            // create a feature rule which allows downgrades
            $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, true);
            $featureRule = $this->createUpdateRule($package, $updates, self::RULE_INTERNAL_ALLOW_UPDATE, (string) $package);

            // create an update rule which does not allow downgrades
            $updates = $this->policy->findUpdatePackages($this, $this->pool, $this->installed, $package, false);
            $rule = $this->createUpdateRule($package, $updates, self::RULE_INTERNAL_ALLOW_UPDATE, (string) $package);

            if ($rule->equals($featureRule)) {
                if ($this->policy->allowUninstall()) {
                    $this->addRule(RuleSet::TYPE_WEAK, $featureRule);
                } else {
                    $this->addRule(RuleSet::TYPE_UPDATE, $rule);
                }
            } else if ($this->policy->allowUninstall()) {
                $this->addRule(RuleSet::TYPE_WEAK, $featureRule);
                $this->addRule(RuleSet::TYPE_WEAK, $rule);
            }
        }

        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'install':
                    $rule = $this->createInstallOneOfRule($job['packages'], self::RULE_JOB_INSTALL, $job['packageName']);
                    $this->addRule(RuleSet::TYPE_JOB, $rule);
                    //$this->ruleToJob[$rule] = $job;
                    break;
                case 'remove':
                    // remove all packages with this name including uninstalled
                    // ones to make sure none of them are picked as replacements

                    // todo: cleandeps
                    foreach ($job['packages'] as $package) {
                        $rule = $this->createRemoveRule($package, self::RULE_JOB_REMOVE);
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                        //$this->ruleToJob[$rule] = $job;
                    }
                    break;
                case 'lock':
                    foreach ($job['packages'] as $package) {
                        if ($this->installed === $package->getRepository()) {
                            $rule = $this->createInstallRule($package, self::RULE_JOB_LOCK);
                        } else {
                            $rule = $this->createRemoveRule($package, self::RULE_JOB_LOCK);
                        }
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
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

        $transaction = array();

        foreach ($this->decisionQueue as $literal) {
            $package = $literal->getPackage();

            // wanted & installed || !wanted & !installed
            if ($literal->isWanted() == ($this->installed == $package->getRepository())) {
                continue;
            }

            $transaction[] = array(
                'job' => ($literal->isWanted()) ? 'install' : 'remove',
                'package' => $package,
            );
        }

        return $transaction;
    }

    protected $decisionQueue = array();
    protected $propagateIndex;
    protected $decisionMap = array();
    protected $branches = array();

    protected function literalFromId($id)
    {
        $package = $this->pool->packageById($id);
        return new Literal($package, $id > 0);
    }

    protected function addDecision(Literal $l, $level)
    {
        if ($l->isWanted()) {
            $this->decisionMap[$l->getPackageId()] = $level;
        } else {
            $this->decisionMap[$l->getPackageId()] = -$level;
        }
    }

    protected function addDecisionId($literalId, $level)
    {
        $packageId = abs($literalId);
        if ($literalId > 0) {
            $this->decisionMap[$packageId] = $level;
        } else {
            $this->decisionMap[$packageId] = -$level;
        }
    }

    protected function decisionsContain(Literal $l)
    {
        return (isset($this->decisionMap[$l->getPackageId()]) && (
            $this->decisionMap[$l->getPackageId()] > 0 && $l->isWanted() ||
            $this->decisionMap[$l->getPackageId()] < 0 && !$l->isWanted()
        ));
    }

    protected function decisionsContainId($literalId)
    {
        $packageId = abs($literalId);
        return (isset($this->decisionMap[$packageId]) && (
            $this->decisionMap[$packageId] > 0 && $literalId > 0 ||
            $this->decisionMap[$packageId] < 0 && $literalId < 0
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

    protected function decisionsConflictId($literalId)
    {
        $packageId = abs($literalId);
        return (isset($this->decisionMap[$packageId]) && (
            $this->decisionMap[$packageId] > 0 && !$literalId < 0 ||
            $this->decisionMap[$packageId] < 0 && $literalId > 0
        ));
    }

    protected function decided(PackageInterface $p)
    {
        return isset($this->decisionMap[$p->getId()]);
    }

    protected function undecided(PackageInterface $p)
    {
        return !isset($this->decisionMap[$p->getId()]);
    }

    protected function decidedInstall(PackageInterface $p) {
        return isset($this->decisionMap[$p->getId()]) && $this->decisionMap[$p->getId()] > 0;
    }

    protected function decidedRemove(PackageInterface $p) {
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
            if (!isset($this->watches[$literal->getId()])) {
                continue;
            }

            for ($rule = $this->watches[$literal->getId()]; $rule !== null; $rule = $nextRule) {
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

    private function analyzeUnsolvableRule($rule, &$lastWeak)
    {
        //if ($this->learntRules &&

/*
  Pool *pool = solv->pool;
  int i;
  Id why = r - solv->rules;

  IF_POOLDEBUG (SAT_DEBUG_UNSOLVABLE)
    solver_printruleclass(solv, SAT_DEBUG_UNSOLVABLE, r);
  if (solv->learntrules && why >= solv->learntrules)
    {
      for (i = solv->learnt_why.elements[why - solv->learntrules]; solv->learnt_pool.elements[i]; i++)
    if (solv->learnt_pool.elements[i] > 0)
      analyze_unsolvable_rule(solv, solv->rules + solv->learnt_pool.elements[i], lastweakp);
      return;
    }
  if (MAPTST(&solv->weakrulemap, why))
    if (!*lastweakp || why > *lastweakp)
      *lastweakp = why;
  /* do not add rpm rules to problem *
  if (why < solv->rpmrules_end)
    return;
  /* turn rule into problem *
  if (why >= solv->jobrules && why < solv->jobrules_end)
    why = -(solv->ruletojob.elements[why - solv->jobrules] + 1);
  /* normalize dup/infarch rules *
  if (why > solv->infarchrules && why < solv->infarchrules_end)
    {
      Id name = pool->solvables[-solv->rules[why].p].name;
      while (why > solv->infarchrules && pool->solvables[-solv->rules[why - 1].p].name == name)
    why--;
    }
  if (why > solv->duprules && why < solv->duprules_end)
    {
      Id name = pool->solvables[-solv->rules[why].p].name;
      while (why > solv->duprules && pool->solvables[-solv->rules[why - 1].p].name == name)
    why--;
    }

  /* return if problem already countains our rule *
  if (solv->problems.count)
    {
      for (i = solv->problems.count - 1; i >= 0; i--)
    if (solv->problems.elements[i] == 0)    /* end of last problem reached? *
      break;
    else if (solv->problems.elements[i] == why)
      return;
    }
  queue_push(&solv->problems, why);
*/
    }

    private function analyzeUnsolvable($conflictRule, $disableRules)
    {
        $this->analyzeUnsolvableRule($conflictRule, $lastWeak);
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

        $this->installedPackages = $this->installed->getPackages();

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
                                    if ($this->installed === $literal->getPackage()->getRepository()) {
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
                        /** TODO: && or || ? **/
                        if (0 === $pass || !isset($this->updateMap[$literal->getPackageId()])) {
                            continue;
                        }

                        $rule = null;
                        /** TODO: huh at package id?!?
                        if (isset($this->rules[Solver::TYPE_UPDATE][$literal->getPackageId()])) {
                            $rule = $this->rules[Solver::TYPE_UPDATE][$literal->getPackageId()];
                        }

                        if ((!$rule || $rule->isDisabled()) && isset($this->rules[Solver::TYPE_FEATURE][$literal->getPackageId()])) {
                            $rule = $this->rules[Solver::TYPE_FEATURE][$literal->getPackageId()];
                        }**/

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

            foreach ($this->rules->getIterator() as $rule) {
                if ($rule->isEnabled()) {
                    $decisionQueue = array();
                }
            }
echo $this->rules;
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
}