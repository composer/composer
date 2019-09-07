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

use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Test\Repository\ArrayRepositoryTest;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class LockTransaction
{
    protected $policy;
    /** @var Pool */
    protected $pool;

    /**
     * packages in current lock file, platform repo or otherwise present
     * @var array
     */
    protected $presentMap;

    /**
     * Packages which cannot be mapped, platform repo, root package, other fixed repos
     * @var array
     */
    protected $unlockableMap;

    protected $decisions;

    protected $resultPackages;

    public function __construct($policy, $pool, $presentMap, $unlockableMap, $decisions)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->presentMap = $presentMap;
        $this->unlockableMap = $unlockableMap;
        $this->decisions = $decisions;

        $this->operations = $this->calculateOperations();
    }

    /**
     * @return OperationInterface[]
     */
    public function getOperations()
    {
        return $this->operations;
    }

    protected function calculateOperations()
    {
        $operations = array();
        $lockMeansUpdateMap = $this->findPotentialUpdates();

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $reason = $decision[Decisions::DECISION_REASON];

            $package = $this->pool->literalToPackage($literal);

            // wanted & !present
            if ($literal > 0 && !isset($this->presentMap[$package->id])) {
                if (isset($lockMeansUpdateMap[abs($literal)]) && !$package instanceof AliasPackage) {
                    $operations[] = new Operation\UpdateOperation($lockMeansUpdateMap[abs($literal)], $package, $reason);

                    // avoid updates to one package from multiple origins
                    $ignoreRemove[$lockMeansUpdateMap[abs($literal)]->id] = true;
                    unset($lockMeansUpdateMap[abs($literal)]);
                } else {
                    if ($package instanceof AliasPackage) {
                        $operations[] = new Operation\MarkAliasInstalledOperation($package, $reason);
                    } else {
                        $operations[] = new Operation\InstallOperation($package, $reason);
                    }
                }
            }
        }

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $reason = $decision[Decisions::DECISION_REASON];
            $package = $this->pool->literalToPackage($literal);

            if ($literal <= 0 && isset($this->presentMap[$package->id]) && !isset($ignoreRemove[$package->id])) {
                if ($package instanceof AliasPackage) {
                    $operations[] = new Operation\MarkAliasUninstalledOperation($package, $reason);
                } else {
                    $operations[] = new Operation\UninstallOperation($package, $reason);
                }
            }
        }

        $this->setResultPackages();

        return $operations;
    }

    // TODO make this a bit prettier instead of the two text indexes?
    public function setResultPackages()
    {
        $this->resultPackages = array('non-dev' => array(), 'dev' => array());
        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];

            if ($literal > 0) {
                $package = $this->pool->literalToPackage($literal);
                if (!isset($this->unlockableMap[$package->id])) {
                    $this->resultPackages['non-dev'][] = $package;
                }
            }
        }
    }

    public function setNonDevPackages(LockTransaction $extractionResult)
    {
        $packages = $extractionResult->getNewLockPackages(false);

        $this->resultPackages['dev'] = $this->resultPackages['non-dev'];
        $this->resultPackages['non-dev'] = array();

        foreach ($packages as $package) {
            foreach ($this->resultPackages['dev'] as $i => $resultPackage) {
                if ($package->getName() == $resultPackage->getName()) {
                    $this->resultPackages['non-dev'][] = $resultPackage;
                    unset($this->resultPackages['dev'][$i]);
                }
            }
        }
    }

    // TODO additionalFixedRepository needs to be looked at here as well?
    public function getNewLockPackages($devMode)
    {
        $packages = array();
        foreach ($this->resultPackages[$devMode ? 'dev' : 'non-dev'] as $package) {
            if (!($package instanceof AliasPackage) && !($package instanceof RootAliasPackage)) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    protected function findPotentialUpdates()
    {
        $lockMeansUpdateMap = array();

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $package = $this->pool->literalToPackage($literal);

            if ($package instanceof AliasPackage) {
                continue;
            }

            // !wanted & present
            if ($literal <= 0 && isset($this->presentMap[$package->id])) {
                // TODO can't we just look at existing rules?
                $updates = $this->policy->findUpdatePackages($this->pool, $package);

                $literals = array($package->id);

                foreach ($updates as $update) {
                    $literals[] = $update->id;
                }

                foreach ($literals as $updateLiteral) {
                    if ($updateLiteral !== $literal && !isset($lockMeansUpdateMap[$updateLiteral])) {
                        $lockMeansUpdateMap[$updateLiteral] = $package;
                    }
                }
            }
        }

        return $lockMeansUpdateMap;
    }
}
