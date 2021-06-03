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

use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Repository\PlatformRepository;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class RuleSetGenerator
{
    protected $policy;
    protected $pool;
    protected $rules;
    protected $addedMap;
    protected $conflictAddedMap;
    protected $addedPackagesByNames;
    protected $conflictsForName;

    public function __construct(PolicyInterface $policy, Pool $pool)
    {
        $this->policy = $policy;
        $this->pool = $pool;
    }

    /**
     * Creates a new rule for the requirements of a package
     *
     * This rule is of the form (-A|B|C), where B and C are the providers of
     * one requirement of the package A.
     *
     * @param  BasePackage $package    The package with a requirement
     * @param  array       $providers  The providers of the requirement
     * @param  int         $reason     A RULE_* constant describing the
     *                                 reason for generating this rule
     * @param  mixed       $reasonData Any data, e.g. the requirement name,
     *                                 that goes with the reason
     * @return Rule|null   The generated rule or null if tautological
     */
    protected function createRequireRule(BasePackage $package, array $providers, $reason, $reasonData = null)
    {
        $literals = array(-$package->id);

        foreach ($providers as $provider) {
            // self fulfilling rule?
            if ($provider === $package) {
                return null;
            }
            $literals[] = $provider->id;
        }

        return new GenericRule($literals, $reason, $reasonData);
    }

    /**
     * Creates a rule to install at least one of a set of packages
     *
     * The rule is (A|B|C) with A, B and C different packages. If the given
     * set of packages is empty an impossible rule is generated.
     *
     * @param  BasePackage[] $packages   The set of packages to choose from
     * @param  int           $reason     A RULE_* constant describing the reason for
     *                                   generating this rule
     * @param  array         $reasonData Additional data like the root require or fix request info
     * @return Rule          The generated rule
     */
    protected function createInstallOneOfRule(array $packages, $reason, $reasonData)
    {
        $literals = array();
        foreach ($packages as $package) {
            $literals[] = $package->id;
        }

        return new GenericRule($literals, $reason, $reasonData);
    }

    /**
     * Creates a rule for two conflicting packages
     *
     * The rule for conflicting packages A and B is (-A|-B). A is called the issuer
     * and B the provider.
     *
     * @param  BasePackage $issuer     The package declaring the conflict
     * @param  BasePackage $provider   The package causing the conflict
     * @param  int         $reason     A RULE_* constant describing the
     *                                 reason for generating this rule
     * @param  mixed       $reasonData Any data, e.g. the package name, that
     *                                 goes with the reason
     * @return Rule|null   The generated rule
     */
    protected function createRule2Literals(BasePackage $issuer, BasePackage $provider, $reason, $reasonData = null)
    {
        // ignore self conflict
        if ($issuer === $provider) {
            return null;
        }

        return new Rule2Literals(-$issuer->id, -$provider->id, $reason, $reasonData);
    }

    protected function createMultiConflictRule(array $packages, $reason, $reasonData = null)
    {
        $literals = array();
        foreach ($packages as $package) {
            $literals[] = -$package->id;
        }

        if (\count($literals) == 2) {
            return new Rule2Literals($literals[0], $literals[1], $reason, $reasonData);
        }

        return new MultiConflictRule($literals, $reason, $reasonData);
    }

    /**
     * Adds a rule unless it duplicates an existing one of any type
     *
     * To be able to directly pass in the result of one of the rule creation
     * methods null is allowed which will not insert a rule.
     *
     * @param int  $type    A TYPE_* constant defining the rule type
     * @param Rule $newRule The rule about to be added
     */
    private function addRule($type, Rule $newRule = null)
    {
        if (!$newRule) {
            return;
        }

        $this->rules->add($newRule, $type);
    }

    protected function addRulesForPackage(BasePackage $package, $ignorePlatformReqs)
    {
        $workQueue = new \SplQueue;
        $workQueue->enqueue($package);

        while (!$workQueue->isEmpty()) {
            /** @var BasePackage $package */
            $package = $workQueue->dequeue();
            if (isset($this->addedMap[$package->id])) {
                continue;
            }

            $this->addedMap[$package->id] = $package;

            if (!$package instanceof AliasPackage) {
                foreach ($package->getNames(false) as $name) {
                    $this->addedPackagesByNames[$name][] = $package;
                }
            } else {
                $workQueue->enqueue($package->getAliasOf());
                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, array($package->getAliasOf()), Rule::RULE_PACKAGE_ALIAS, $package));

                // aliases must be installed with their main package, so create a rule the other way around as well
                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package->getAliasOf(), array($package), Rule::RULE_PACKAGE_INVERSE_ALIAS, $package->getAliasOf()));

                // if alias package has no self.version requires, its requirements do not
                // need to be added as the aliased package processing will take care of it
                if (!$package->hasSelfVersionRequires()) {
                    continue;
                }
            }

            foreach ($package->getRequires() as $link) {
                if ((true === $ignorePlatformReqs || (is_array($ignorePlatformReqs) && in_array($link->getTarget(), $ignorePlatformReqs, true))) && PlatformRepository::isPlatformPackage($link->getTarget())) {
                    continue;
                }

                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, $possibleRequires, Rule::RULE_PACKAGE_REQUIRES, $link));

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }
        }
    }

    protected function addConflictRules($ignorePlatformReqs = false)
    {
        /** @var BasePackage $package */
        foreach ($this->addedMap as $package) {
            foreach ($package->getConflicts() as $link) {
                // even if conlict ends up being with an alias, there would be at least one actual package by this name
                if (!isset($this->addedPackagesByNames[$link->getTarget()])) {
                    continue;
                }

                if ((true === $ignorePlatformReqs || (is_array($ignorePlatformReqs) && in_array($link->getTarget(), $ignorePlatformReqs, true))) && PlatformRepository::isPlatformPackage($link->getTarget())) {
                    continue;
                }

                $conflicts = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                foreach ($conflicts as $conflict) {
                    // define the conflict rule for regular packages, for alias packages it's only needed if the name
                    // matches the conflict exactly, otherwise the name match is by provide/replace which means the
                    // package which this is an alias of will conflict anyway, so no need to create additional rules
                    if (!$conflict instanceof AliasPackage || $conflict->getName() === $link->getTarget()) {
                        $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRule2Literals($package, $conflict, Rule::RULE_PACKAGE_CONFLICT, $link));
                    }
                }
            }
        }

        foreach ($this->addedPackagesByNames as $name => $packages) {
            if (\count($packages) > 1) {
                $reason = Rule::RULE_PACKAGE_SAME_NAME;
                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createMultiConflictRule($packages, $reason, $name));
            }
        }
    }

    protected function addRulesForRequest(Request $request, $ignorePlatformReqs)
    {
        foreach ($request->getFixedPackages() as $package) {
            if ($package->id == -1) {
                // fixed package was not added to the pool as it did not pass the stability requirements, this is fine
                if ($this->pool->isUnacceptableFixedOrLockedPackage($package)) {
                    continue;
                }

                // otherwise, looks like a bug
                throw new \LogicException("Fixed package ".$package->getPrettyString()." was not added to solver pool.");
            }

            $this->addRulesForPackage($package, $ignorePlatformReqs);

            $rule = $this->createInstallOneOfRule(array($package), Rule::RULE_FIXED, array(
                'package' => $package,
            ));
            $this->addRule(RuleSet::TYPE_REQUEST, $rule);
        }

        foreach ($request->getRequires() as $packageName => $constraint) {
            if ((true === $ignorePlatformReqs || (is_array($ignorePlatformReqs) && in_array($packageName, $ignorePlatformReqs, true))) && PlatformRepository::isPlatformPackage($packageName)) {
                continue;
            }

            $packages = $this->pool->whatProvides($packageName, $constraint);
            if ($packages) {
                foreach ($packages as $package) {
                    $this->addRulesForPackage($package, $ignorePlatformReqs);
                }

                $rule = $this->createInstallOneOfRule($packages, Rule::RULE_ROOT_REQUIRE, array(
                    'packageName' => $packageName,
                    'constraint' => $constraint,
                ));
                $this->addRule(RuleSet::TYPE_REQUEST, $rule);
            }
        }
    }

    protected function addRulesForRootAliases($ignorePlatformReqs)
    {
        foreach ($this->pool->getPackages() as $package) {
            // ensure that rules for root alias packages and aliases of packages which were loaded are also loaded
            // even if the alias itself isn't required, otherwise a package could be installed without its alias which
            // leads to unexpected behavior
            if (!isset($this->addedMap[$package->id]) &&
                $package instanceof AliasPackage &&
                ($package->isRootPackageAlias() || isset($this->addedMap[$package->getAliasOf()->id]))
            ) {
                $this->addRulesForPackage($package, $ignorePlatformReqs);
            }
        }
    }

    /**
     * @param bool|array $ignorePlatformReqs
     */
    public function getRulesFor(Request $request, $ignorePlatformReqs = false)
    {
        $this->rules = new RuleSet;

        $this->addedMap = array();
        $this->conflictAddedMap = array();
        $this->addedPackagesByNames = array();
        $this->conflictsForName = array();

        $this->addRulesForRequest($request, $ignorePlatformReqs);

        $this->addRulesForRootAliases($ignorePlatformReqs);

        $this->addConflictRules($ignorePlatformReqs);

        // Remove references to packages
        $this->addedMap = $this->addedPackagesByNames = null;

        return $this->rules;
    }
}
