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

use Composer\Package\AliasPackage;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;

/**
 * Optimizes a given pool
 *
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class PoolOptimizer
{
    /**
     * @var PolicyInterface
     */
    private $policy;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Pool
     */
    private $pool;

    private $dependencyHashes = array();
    private $irremovablePackageConstraints = array();
    private $irremovablePackages = array();
    private $requireConstraintsPerPackage = array();
    private $conflictConstraintsPerPackage = array();
    private $packagesToRemove = array();

    public function __construct(PolicyInterface $policy)
    {
        $this->policy = $policy;
    }

    /**
     * @param Request $request
     * @param Pool    $pool
     *
     * @return Pool
     */
    public function optimize(Request $request, Pool $pool)
    {
        $this->request = $request;
        $this->pool = $pool;

        $this->prepare();

        $this->optimizeByIdenticalDependencies();

        // No need to run this recursively at the moment
        // because the current optimizations cannot provide
        // even more gains when ran again. Might change
        // in the future with additional optimizations.

        $this->dependencyHashes = array();
        $this->irremovablePackageConstraints = array();
        $this->irremovablePackages = array();
        $this->requireConstraintsPerPackage = array();
        $this->conflictConstraintsPerPackage = array();
        $this->packagesToRemove = array();

        return $this->pool;
    }

    private function prepare()
    {
        // Mark fixed or locked packages as irremovable
        foreach ($this->request->getFixedOrLockedPackages() as $package) {
            $this->addIrremovablePackageConstraint($package->getName(), new Constraint('==', $package->getVersion()));
        }

        // Extract requested package requirements
        foreach ($this->request->getRequires() as $require => $constraint) {
            $constraint = Intervals::compactConstraint($constraint);
            $this->requireConstraintsPerPackage[$require][(string) $constraint] = $constraint;
        }

        // First pass over all packages to extract information and mark package constraints irremovable
        foreach ($this->pool->getPackages() as $i => $package) {
            // Extract package requirements
            foreach ($package->getRequires() as $link) {
                $constraint = Intervals::compactConstraint($link->getConstraint());
                $this->requireConstraintsPerPackage[$link->getTarget()][(string) $constraint] = $constraint;
            }
            // Extract package conflicts
            foreach ($package->getConflicts() as $link) {
                $constraint = Intervals::compactConstraint($link->getConstraint());
                $this->conflictConstraintsPerPackage[$link->getTarget()][(string) $constraint] = $constraint;
            }

            // Mark the alias package as well as the aliased package as irremovable (maybe this can be improved?)
            if ($package instanceof AliasPackage) {
                $this->addIrremovablePackageConstraint($package->getName(), new Constraint('==', $package->getVersion()));
                $this->addIrremovablePackageConstraint($package->getAliasOf()->getName(), new Constraint('==', $package->getAliasOf()->getVersion()));
            }
        }

        // Mark the packages as irremovable based on the constraints
        foreach ($this->pool->getPackages() as $i => $package) {
            if (!isset($this->irremovablePackageConstraints[$package->getName()])) {
                continue;
            }

            if (CompilingMatcher::match($this->irremovablePackageConstraints[$package->getName()], Constraint::OP_EQ, $package->getVersion())) {
                $this->irremovablePackages[$package->id] = true;
            }
        }
    }

    private function addIrremovablePackageConstraint($packageName, ConstraintInterface $constraint)
    {
        if (!isset($this->irremovablePackageConstraints[$packageName])) {
            $this->irremovablePackageConstraints[$packageName] = $constraint;

            return;
        }

        // Do not use Intervals::compactConstraint() here (it hurts performance)
        $this->irremovablePackageConstraints[$packageName] = new MultiConstraint(array(
            $this->irremovablePackageConstraints[$packageName],
            $constraint,
        ), false);
    }

    private function applyRemovalsToPool()
    {
        $packages = array();
        foreach ($this->pool->getPackages() as $package) {
            if (!isset($this->packagesToRemove[$package->id])) {
                $packages[] = $package;
            }
        }

        $this->pool = new Pool($packages, $this->pool->getUnacceptableFixedOrLockedPackages());

        // Reset package removals
        $this->packagesToRemove = array();
    }

    private function optimizeByIdenticalDependencies()
    {
        $identicalDefinitionPerPackage = array();
        $packageIdsToRemove = array();

        foreach ($this->pool->getPackages() as $i => $package) {

            // If that package was already marked irremovable, we can skip
            // the entire process for it
            if (isset($this->irremovablePackages[$package->id])) {
                continue;
            }

            $packageIdsToRemove[$package->id] = true;

            $dependencyHash = $this->calculateDependencyHash($package);

            foreach ($package->getNames(false) as $packageName) {
                if (!isset($this->requireConstraintsPerPackage[$packageName])) {
                    continue;
                }

                foreach ($this->requireConstraintsPerPackage[$packageName] as $requireConstraint) {
                    $groupHashParts = array();

                    if (CompilingMatcher::match($requireConstraint, Constraint::OP_EQ, $package->getVersion())) {
                        $groupHashParts[] = 'require:' . (string) $requireConstraint;
                    }

                    if ($package->getReplaces()) {
                        foreach ($package->getReplaces() as $link) {
                            // Make sure we do not replace ourselves (if someone made a mistake and tagged it)
                            // See e.g. https://github.com/BabDev/Pagerfanta/commit/fd00eb74632fecc0265327e9fe0eddc08c72b238#diff-b5d0ee8c97c7abd7e3fa29b9a27d1780
                            // TODO: should that go into package itself?
                            if ($package->getName() === $link->getTarget()) {
                                continue;
                            }

                            if (CompilingMatcher::match($link->getConstraint(), Constraint::OP_EQ, $package->getVersion())) {
                                // Use the same hash part as the regular require hash because that's what the replacement does
                                $groupHashParts[] = 'require:' . (string) $link->getConstraint();
                            }
                        }
                    }

                    if (isset($this->conflictConstraintsPerPackage[$packageName])) {
                        foreach ($this->conflictConstraintsPerPackage[$packageName] as $conflictConstraint) {
                            if (CompilingMatcher::match($conflictConstraint, Constraint::OP_EQ, $package->getVersion())) {
                                $groupHashParts[] = 'conflict:' . (string) $requireConstraint;
                            }
                        }
                    }

                    if (!$groupHashParts) {
                        continue;
                    }

                    $identicalDefinitionPerPackage[$packageName][implode('', $groupHashParts)][$dependencyHash][] = $package;
                }
            }
        }

        foreach ($identicalDefinitionPerPackage as $package => $constraintGroups) {
            foreach ($constraintGroups as $constraintGroup) {
                foreach ($constraintGroup as $hash => $packages) {

                    // Only one package in this constraint group has the same requirements, we're not allowed to remove that package
                    if (1 === \count($packages)) {
                        unset($packageIdsToRemove[$packages[0]->id]);
                        continue;
                    }

                    // Otherwise we find out which one is the preferred package in this constraint group which is
                    // then not allowed to be removed either
                    $literals = array();

                    foreach ($packages as $package) {
                        $literals[] = $package->id;
                    }

                    foreach ($this->policy->selectPreferredPackages($this->pool, $literals) as $preferredLiteral) {
                        unset($packageIdsToRemove[$preferredLiteral]);
                    }
                }
            }
        }

        foreach (array_keys($packageIdsToRemove) as $id) {
            $this->markPackageForRemoval($this->pool->packageById($id));
        }

        // Apply removals
        $this->applyRemovalsToPool();
    }

    private function calculateDependencyHash(PackageInterface $package)
    {
        if (isset($this->dependencyHashes[$package->id])) {
            return $this->dependencyHashes[$package->id];
        }

        $hash = array();

        $hashRelevantLinks = array(
            'requires' => $package->getRequires(),
            'conflicts' => $package->getConflicts(),
            'replaces' => $package->getReplaces(),
            'provides' => $package->getProvides(),
        );

        foreach ($hashRelevantLinks as $key => $links) {
            // start new hash section
            $hash[] = $key . ':';

            $subhash = array();

            foreach ($links as $link) {
                // To get the best dependency hash matches we should use Intervals::compactConstraint() here.
                // However, the majority of projects are going to specify their constraints already pretty
                // much in the best variant possible. In other words, we'd be wasting time here and it would actually hurt
                // performance more than the additional few packages that could be filtered out would benefit the process.
                $subhash[$link->getTarget()] = (string) $link->getConstraint();
            }

            // Sort for best result
            ksort($subhash);

            foreach ($subhash as $target => $constraint) {
                $hash[] = $target . '@' . $constraint;
            }
        }

        return $this->dependencyHashes[$package->id] = implode('', $hash);
    }

    private function markPackageForRemoval(PackageInterface $package)
    {
        // We are not allowed to remove packages if they have been marked as irremovable
        if (isset($this->irremovablePackages[$package->id])) {
            return false;
        }

        $this->packagesToRemove[$package->id] = true;

        return true;
    }
}
