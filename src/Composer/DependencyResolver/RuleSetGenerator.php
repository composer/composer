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
use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @phpstan-import-type ReasonData from Rule
 */
class RuleSetGenerator
{
    /** @var PolicyInterface */
    protected $policy;
    /** @var Pool */
    protected $pool;
    /** @var RuleSet */
    protected $rules;
    /** @var array<int, BasePackage> */
    protected $addedMap = array();
    /** @var array<string, BasePackage[]> */
    protected $addedPackagesByNames = array();

    public function __construct(PolicyInterface $policy, Pool $pool)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->rules = new RuleSet;
    }

    /**
     * Creates a new rule for the requirements of a package
     *
     * This rule is of the form (-A|B|C), where B and C are the providers of
     * one requirement of the package A.
     *
     * @param  BasePackage $package The package with a requirement
     * @param  BasePackage[] $providers The providers of the requirement
     * @param  Rule::RULE_* $reason A RULE_* constant describing the reason for generating this rule
     * @param  mixed $reasonData Any data, e.g. the requirement name, that goes with the reason
     * @return Rule|null The generated rule or null if tautological
     *
     * @phpstan-param ReasonData $reasonData
     */
    protected function createRequireRule(BasePackage $package, array $providers, $reason, $reasonData = null): ?Rule
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
     * @param  Rule::RULE_*  $reason     A RULE_* constant describing the reason for
     *                                   generating this rule
     * @param  mixed         $reasonData Additional data like the root require or fix request info
     * @return Rule          The generated rule
     *
     * @phpstan-param ReasonData $reasonData
     */
    protected function createInstallOneOfRule(array $packages, $reason, $reasonData): Rule
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
     * @param BasePackage $issuer The package declaring the conflict
     * @param BasePackage $provider The package causing the conflict
     * @param Rule::RULE_* $reason A RULE_* constant describing the reason for generating this rule
     * @param mixed $reasonData Any data, e.g. the package name, that goes with the reason
     * @return ?Rule The generated rule
     *
     * @phpstan-param ReasonData $reasonData
     */
    protected function createRule2Literals(BasePackage $issuer, BasePackage $provider, $reason, $reasonData = null): ?Rule
    {
        // ignore self conflict
        if ($issuer === $provider) {
            return null;
        }

        return new Rule2Literals(-$issuer->id, -$provider->id, $reason, $reasonData);
    }

    /**
     * @param BasePackage[] $packages
     * @param Rule::RULE_* $reason A RULE_* constant
     * @param mixed $reasonData
     * @return Rule
     *
     * @phpstan-param ReasonData $reasonData
     */
    protected function createMultiConflictRule(array $packages, $reason, $reasonData): Rule
    {
        $literals = array();
        foreach ($packages as $package) {
            $literals[] = -$package->id;
        }

        if (\count($literals) === 2) {
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
     * @param RuleSet::TYPE_* $type A TYPE_* constant defining the rule type
     * @param Rule $newRule The rule about to be added
     *
     * @return void
     */
    private function addRule($type, Rule $newRule = null): void
    {
        if (!$newRule) {
            return;
        }

        $this->rules->add($newRule, $type);
    }

    /**
     * @return void
     */
    protected function addRulesForPackage(BasePackage $package, PlatformRequirementFilterInterface $platformRequirementFilter): void
    {
        /** @var \SplQueue<BasePackage> */
        $workQueue = new \SplQueue;
        $workQueue->enqueue($package);

        while (!$workQueue->isEmpty()) {
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
                $constraint = $link->getConstraint();
                if ($platformRequirementFilter->isIgnored($link->getTarget())) {
                    continue;
                } elseif ($platformRequirementFilter instanceof IgnoreListPlatformRequirementFilter) {
                    $constraint = $platformRequirementFilter->filterConstraint($link->getTarget(), $constraint);
                }

                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $constraint);

                $this->addRule(RuleSet::TYPE_PACKAGE, $this->createRequireRule($package, $possibleRequires, Rule::RULE_PACKAGE_REQUIRES, $link));

                foreach ($possibleRequires as $require) {
                    $workQueue->enqueue($require);
                }
            }
        }
    }

    /**
     * @return void
     */
    protected function addConflictRules(PlatformRequirementFilterInterface $platformRequirementFilter): void
    {
        /** @var BasePackage $package */
        foreach ($this->addedMap as $package) {
            foreach ($package->getConflicts() as $link) {
                // even if conlict ends up being with an alias, there would be at least one actual package by this name
                if (!isset($this->addedPackagesByNames[$link->getTarget()])) {
                    continue;
                }

                $constraint = $link->getConstraint();
                if ($platformRequirementFilter->isIgnored($link->getTarget())) {
                    continue;
                } elseif ($platformRequirementFilter instanceof IgnoreListPlatformRequirementFilter) {
                    $constraint = $platformRequirementFilter->filterConstraint($link->getTarget(), $constraint);
                }

                $conflicts = $this->pool->whatProvides($link->getTarget(), $constraint);

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

    /**
     * @return void
     */
    protected function addRulesForRequest(Request $request, PlatformRequirementFilterInterface $platformRequirementFilter): void
    {
        foreach ($request->getFixedPackages() as $package) {
            if ($package->id === -1) {
                // fixed package was not added to the pool as it did not pass the stability requirements, this is fine
                if ($this->pool->isUnacceptableFixedOrLockedPackage($package)) {
                    continue;
                }

                // otherwise, looks like a bug
                throw new \LogicException("Fixed package ".$package->getPrettyString()." was not added to solver pool.");
            }

            $this->addRulesForPackage($package, $platformRequirementFilter);

            $rule = $this->createInstallOneOfRule(array($package), Rule::RULE_FIXED, array(
                'package' => $package,
            ));
            $this->addRule(RuleSet::TYPE_REQUEST, $rule);
        }

        foreach ($request->getRequires() as $packageName => $constraint) {
            if ($platformRequirementFilter->isIgnored($packageName)) {
                continue;
            } elseif ($platformRequirementFilter instanceof IgnoreListPlatformRequirementFilter) {
                $constraint = $platformRequirementFilter->filterConstraint($packageName, $constraint);
            }

            $packages = $this->pool->whatProvides($packageName, $constraint);
            if ($packages) {
                foreach ($packages as $package) {
                    $this->addRulesForPackage($package, $platformRequirementFilter);
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
     * @return void
     */
    protected function addRulesForRootAliases(PlatformRequirementFilterInterface $platformRequirementFilter): void
    {
        foreach ($this->pool->getPackages() as $package) {
            // ensure that rules for root alias packages and aliases of packages which were loaded are also loaded
            // even if the alias itself isn't required, otherwise a package could be installed without its alias which
            // leads to unexpected behavior
            if (!isset($this->addedMap[$package->id]) &&
                $package instanceof AliasPackage &&
                ($package->isRootPackageAlias() || isset($this->addedMap[$package->getAliasOf()->id]))
            ) {
                $this->addRulesForPackage($package, $platformRequirementFilter);
            }
        }
    }

    /**
     * @return RuleSet
     */
    public function getRulesFor(Request $request, PlatformRequirementFilterInterface $platformRequirementFilter = null): RuleSet
    {
        $platformRequirementFilter = $platformRequirementFilter ?: PlatformRequirementFilterFactory::ignoreNothing();

        $this->addRulesForRequest($request, $platformRequirementFilter);

        $this->addRulesForRootAliases($platformRequirementFilter);

        $this->addConflictRules($platformRequirementFilter);

        // Remove references to packages
        $this->addedMap = $this->addedPackagesByNames = array();

        $rules = $this->rules;

        $this->rules = new RuleSet;

        return $rules;
    }
}
