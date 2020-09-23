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

use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;

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
    protected $addedPackages;
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
     * @param  PackageInterface $package    The package with a requirement
     * @param  array            $providers  The providers of the requirement
     * @param  int              $reason     A RULE_* constant describing the
     *                                      reason for generating this rule
     * @param  mixed            $reasonData Any data, e.g. the requirement name,
     *                                      that goes with the reason
     * @return Rule|null             The generated rule or null if tautological
     */
    protected function createRequireRule(PackageInterface $package, array $providers, $reason, $reasonData = null)
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
     * @param  array $packages The set of packages to choose from
     * @param  int   $reason   A RULE_* constant describing the reason for
     *                         generating this rule
     * @param  array $reasonData Additional data like the root require or fix request info
     * @return Rule  The generated rule
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
     * @param  PackageInterface $issuer     The package declaring the conflict
     * @param  PackageInterface $provider   The package causing the conflict
     * @param  int              $reason     A RULE_* constant describing the
     *                                      reason for generating this rule
     * @param  mixed            $reasonData Any data, e.g. the package name, that
     *                                      goes with the reason
     * @return Rule|null             The generated rule
     */
    protected function createRule2Literals(PackageInterface $issuer, PackageInterface $provider, $reason, $reasonData = null)
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

    protected function addRulesForPackage(PackageInterface $package, $ignorePlatformReqs)
    {
        $workQueue = new \SplQueue;
        $workQueue->enqueue($package);

        while (!$workQueue->isEmpty()) {
            /** @var PackageInterface $package */
            $package = $workQueue->dequeue();
            if (isset($this->addedMap[$package->id])) {
                continue;
            }

            $this->addedMap[$package->id] = true;

            $this->addedPackages[] = $package;
            if (!$package instanceof AliasPackage) {
                foreach ($package->getNames(false) as $name) {
                    $this->addedPackagesByNames[$name][] = $package;
                }
            } else {
                $workQueue->enqueue($package->getAliasOf());
                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, array($package->getAliasOf()), Rule::RULE_PACKAGE_ALIAS, $package));

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
        /** @var PackageInterface $package */
        foreach ($this->addedPackages as $package) {
            foreach ($package->getConflicts() as $link) {
                if (!isset($this->addedPackagesByNames[$link->getTarget()])) {
                    continue;
                }

                if ((true === $ignorePlatformReqs || (is_array($ignorePlatformReqs) && in_array($link->getTarget(), $ignorePlatformReqs, true))) && PlatformRepository::isPlatformPackage($link->getTarget())) {
                    continue;
                }

                /** @var PackageInterface $possibleConflict */
                foreach ($this->addedPackagesByNames[$link->getTarget()] as $possibleConflict) {
                    if ($this->pool->match($possibleConflict, $link->getTarget(), $link->getConstraint())) {
                        $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRule2Literals($package, $possibleConflict, Rule::RULE_PACKAGE_CONFLICT, $link));
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
                throw new \LogicException("Fixed package ".$package->getName()." ".$package->getVersion().($package instanceof AliasPackage ? " (alias)" : "")." was not added to solver pool.");
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

    /**
     * @param bool|array $ignorePlatformReqs
     */
    public function getRulesFor(Request $request, $ignorePlatformReqs = false)
    {
        $this->rules = new RuleSet;

        $this->addedMap = array();
        $this->conflictAddedMap = array();
        $this->addedPackages = array();
        $this->addedPackagesByNames = array();
        $this->conflictsForName = array();

        $this->addRulesForRequest($request, $ignorePlatformReqs);

        $this->addConflictRules($ignorePlatformReqs);

        // Remove references to packages
        $this->addedPackages = $this->addedPackagesByNames = null;

        return $this->rules;
    }
}
