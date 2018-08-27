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

use Composer\Package\PackageInterface;
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
    protected $jobs;
    protected $installedMap;
    protected $whitelistedMap;
    protected $addedMap;
    protected $conflictAddedMap;
    protected $addedPackages;
    protected $addedPackagesByNames;

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
     * @return Rule             The generated rule or null if tautological
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
     * @param  array $job      The job this rule was created from
     * @return Rule  The generated rule
     */
    protected function createInstallOneOfRule(array $packages, $reason, $job)
    {
        $literals = array();
        foreach ($packages as $package) {
            $literals[] = $package->id;
        }

        return new GenericRule($literals, $reason, $job['packageName'], $job);
    }

    /**
     * Creates a rule to remove a package
     *
     * The rule for a package A is (-A).
     *
     * @param  PackageInterface $package The package to be removed
     * @param  int              $reason  A RULE_* constant describing the
     *                                   reason for generating this rule
     * @param  array            $job     The job this rule was created from
     * @return Rule             The generated rule
     */
    protected function createRemoveRule(PackageInterface $package, $reason, $job)
    {
        return new GenericRule(array(-$package->id), $reason, $job['packageName'], $job);
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
     * @return Rule             The generated rule
     */
    protected function createRule2Literals(PackageInterface $issuer, PackageInterface $provider, $reason, $reasonData = null)
    {
        // ignore self conflict
        if ($issuer === $provider) {
            return null;
        }

        return new Rule2Literals(-$issuer->id, -$provider->id, $reason, $reasonData);
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

    protected function whitelistFromPackage(PackageInterface $package)
    {
        $workQueue = new \SplQueue;
        $workQueue->enqueue($package);

        while (!$workQueue->isEmpty()) {
            $package = $workQueue->dequeue();
            if (isset($this->whitelistedMap[$package->id])) {
                continue;
            }

            $this->whitelistedMap[$package->id] = true;

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint(), true);

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }

            $obsoleteProviders = $this->pool->whatProvides($package->getName(), null, true);

            foreach ($obsoleteProviders as $provider) {
                if ($provider === $package) {
                    continue;
                }

                if (($package instanceof AliasPackage) && $package->getAliasOf() === $provider) {
                    $workQueue->enqueue($provider);
                }
            }
        }
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
            foreach ($package->getNames() as $name) {
                $this->addedPackagesByNames[$name][] = $package;
            }

            foreach ($package->getRequires() as $link) {
                if ($ignorePlatformReqs && preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $link->getTarget())) {
                    continue;
                }

                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, $possibleRequires, Rule::RULE_PACKAGE_REQUIRES, $link));

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }

            $packageName = $package->getName();
            $obsoleteProviders = $this->pool->whatProvides($packageName, null);

            foreach ($obsoleteProviders as $provider) {
                if ($provider === $package) {
                    continue;
                }

                if (($package instanceof AliasPackage) && $package->getAliasOf() === $provider) {
                    $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, array($provider), Rule::RULE_PACKAGE_ALIAS, $package));
                } elseif (!$this->obsoleteImpossibleForAlias($package, $provider)) {
                    $reason = ($packageName == $provider->getName()) ? Rule::RULE_PACKAGE_SAME_NAME : Rule::RULE_PACKAGE_IMPLICIT_OBSOLETES;
                    $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRule2Literals($package, $provider, $reason, $package));
                }
            }
        }
    }

    protected function addConflictRules()
    {
        /** @var PackageInterface $package */
        foreach ($this->addedPackages as $package) {
            foreach ($package->getConflicts() as $link) {
                if (!isset($this->addedPackagesByNames[$link->getTarget()])) {
                    continue;
                }

                /** @var PackageInterface $possibleConflict */
                foreach ($this->addedPackagesByNames[$link->getTarget()] as $possibleConflict) {
                    $conflictMatch = $this->pool->match($possibleConflict, $link->getTarget(), $link->getConstraint(), true);

                    if ($conflictMatch === Pool::MATCH || $conflictMatch === Pool::MATCH_REPLACE) {
                        $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRule2Literals($package, $possibleConflict, Rule::RULE_PACKAGE_CONFLICT, $link));
                    }

                }
            }

            // check obsoletes and implicit obsoletes of a package
            $isInstalled = isset($this->installedMap[$package->id]);

            foreach ($package->getReplaces() as $link) {
                if (!isset($this->addedPackagesByNames[$link->getTarget()])) {
                    continue;
                }

                /** @var PackageInterface $possibleConflict */
                foreach ($this->addedPackagesByNames[$link->getTarget()] as $provider) {
                    if ($provider === $package) {
                        continue;
                    }

                    if (!$this->obsoleteImpossibleForAlias($package, $provider)) {
                        $reason = $isInstalled ? Rule::RULE_INSTALLED_PACKAGE_OBSOLETES : Rule::RULE_PACKAGE_OBSOLETES;
                        $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRule2Literals($package, $provider, $reason, $link));
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

    protected function whitelistFromJobs()
    {
        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'install':
                    $packages = $this->pool->whatProvides($job['packageName'], $job['constraint'], true);
                    foreach ($packages as $package) {
                        $this->whitelistFromPackage($package);
                    }
                    break;
            }
        }
    }

    protected function addRulesForJobs($ignorePlatformReqs)
    {
        foreach ($this->jobs as $job) {
            switch ($job['cmd']) {
                case 'install':
                    if (!$job['fixed'] && $ignorePlatformReqs && preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $job['packageName'])) {
                        break;
                    }

                    $packages = $this->pool->whatProvides($job['packageName'], $job['constraint']);
                    if ($packages) {
                        foreach ($packages as $package) {
                            if (!isset($this->installedMap[$package->id])) {
                                $this->addRulesForPackage($package, $ignorePlatformReqs);
                            }
                        }

                        $rule = $this->createInstallOneOfRule($packages, Rule::RULE_JOB_INSTALL, $job);
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                    }
                    break;
                case 'remove':
                    // remove all packages with this name including uninstalled
                    // ones to make sure none of them are picked as replacements
                    $packages = $this->pool->whatProvides($job['packageName'], $job['constraint']);
                    foreach ($packages as $package) {
                        $rule = $this->createRemoveRule($package, Rule::RULE_JOB_REMOVE, $job);
                        $this->addRule(RuleSet::TYPE_JOB, $rule);
                    }
                    break;
            }
        }
    }

    public function getRulesFor($jobs, $installedMap, $ignorePlatformReqs = false)
    {
        $this->jobs = $jobs;
        $this->rules = new RuleSet;
        $this->installedMap = $installedMap;

        $this->whitelistedMap = array();
        foreach ($this->installedMap as $package) {
            $this->whitelistFromPackage($package);
        }
        $this->whitelistFromJobs();

        $this->pool->setWhitelist($this->whitelistedMap);

        $this->addedMap = array();
        $this->conflictAddedMap = array();
        $this->addedPackages = array();
        $this->addedPackagesByNames = array();
        foreach ($this->installedMap as $package) {
            $this->addRulesForPackage($package, $ignorePlatformReqs);
        }

        $this->addRulesForJobs($ignorePlatformReqs);

        $this->addConflictRules();

        // Remove references to packages
        $this->addedPackages = $this->addedPackagesByNames = null;

        return $this->rules;
    }
}
