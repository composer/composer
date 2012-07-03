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
use Composer\DependencyResolver\Operation;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Transaction
{
    protected $policy;
    protected $pool;
    protected $installedMap;
    protected $decisions;
    protected $transaction;

    public function __construct($policy, $pool, $installedMap, $decisions)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->installedMap = $installedMap;
        $this->decisions = $decisions;
        $this->transaction = array();
    }

    public function getOperations()
    {
        $installMeansUpdateMap = $this->findUpdates();

        $updateMap = array();
        $installMap = array();
        $uninstallMap = array();

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $reason = $decision[Decisions::DECISION_REASON];

            $package = $this->pool->literalToPackage($literal);

            // wanted & installed || !wanted & !installed
            if (($literal > 0) == (isset($this->installedMap[$package->getId()]))) {
                continue;
            }

            if ($literal > 0) {
                if (isset($installMeansUpdateMap[abs($literal)]) && !$package instanceof AliasPackage) {

                    $source = $installMeansUpdateMap[abs($literal)];

                    $updateMap[$package->getId()] = array(
                        'package' => $package,
                        'source' => $source,
                        'reason' => $reason,
                    );

                    // avoid updates to one package from multiple origins
                    unset($installMeansUpdateMap[abs($literal)]);
                    $ignoreRemove[$source->getId()] = true;
                } else {
                    $installMap[$package->getId()] = array(
                        'package' => $package,
                        'reason' => $reason,
                    );
                }
            }
        }

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $package = $this->pool->literalToPackage($literal);

            if ($literal <= 0 &&
                isset($this->installedMap[$package->getId()]) &&
                !isset($ignoreRemove[$package->getId()])) {
                $uninstallMap[$package->getId()] = array(
                    'package' => $package,
                    'reason' => $reason,
                );

            }
        }

        $this->transactionFromMaps($installMap, $updateMap, $uninstallMap);

        return $this->transaction;
    }

    protected function transactionFromMaps($installMap, $updateMap, $uninstallMap)
    {
        $queue = array_map(function ($operation) {
                return $operation['package'];
            },
            $this->findRootPackages($installMap, $updateMap)
        );

        $visited = array();

        while (!empty($queue)) {
            $package = array_pop($queue);
            $packageId = $package->getId();

            if (!isset($visited[$packageId])) {
                array_push($queue, $package);

                if ($package instanceof AliasPackage) {
                    array_push($queue, $package->getAliasOf());
                } else {
                    foreach ($package->getRequires() as $link) {
                        $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                        foreach ($possibleRequires as $require) {
                            array_push($queue, $require);
                        }
                    }
                }

                $visited[$package->getId()] = true;
            } else {
                if (isset($installMap[$packageId])) {
                    $this->install(
                        $installMap[$packageId]['package'],
                        $installMap[$packageId]['reason']
                    );
                    unset($installMap[$packageId]);
                }
                if (isset($updateMap[$packageId])) {
                    $this->update(
                        $updateMap[$packageId]['source'],
                        $updateMap[$packageId]['package'],
                        $updateMap[$packageId]['reason']
                    );
                    unset($updateMap[$packageId]);
                }
            }
        }

        foreach ($uninstallMap as $uninstall) {
            $this->uninstall($uninstall['package'], $uninstall['reason']);
        }
    }

    protected function findRootPackages($installMap, $updateMap)
    {
        $packages = $installMap + $updateMap;
        $roots = $packages;

        foreach ($packages as $packageId => $operation) {
            $package = $operation['package'];

            if (!isset($roots[$packageId])) {
                continue;
            }

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->pool->whatProvides($link->getTarget(), $link->getConstraint());

                foreach ($possibleRequires as $require) {
                    unset($roots[$require->getId()]);
                }
            }
        }

        return $roots;
    }

    protected function findUpdates()
    {
        $installMeansUpdateMap = array();

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $package = $this->pool->literalToPackage($literal);

            if ($package instanceof AliasPackage) {
                continue;
            }

            // !wanted & installed
            if ($literal <= 0 && isset($this->installedMap[$package->getId()])) {
                $updates = $this->policy->findUpdatePackages($this->pool, $this->installedMap, $package);

                $literals = array($package->getId());

                foreach ($updates as $update) {
                    $literals[] = $update->getId();
                }

                foreach ($literals as $updateLiteral) {
                    if ($updateLiteral !== $literal) {
                        $installMeansUpdateMap[abs($updateLiteral)] = $package;
                    }
                }
            }
        }

        return $installMeansUpdateMap;
    }

    protected function install($package, $reason)
    {
        if ($package instanceof AliasPackage) {
            return $this->markAliasInstalled($package, $reason);
        }

        $this->transaction[] = new Operation\InstallOperation($package, $reason);
    }

    protected function update($from, $to, $reason)
    {
        $this->transaction[] = new Operation\UpdateOperation($from, $to, $reason);
    }

    protected function uninstall($package, $reason)
    {
        if ($package instanceof AliasPackage) {
            return $this->markAliasUninstalled($package, $reason);
        }

        $this->transaction[] = new Operation\UninstallOperation($package, $reason);
    }

    protected function markAliasInstalled($package, $reason)
    {
        $this->transaction[] = new Operation\MarkAliasInstalledOperation($package, $reason);
    }

    protected function markAliasUninstalled($package, $reason)
    {
        $this->transaction[] = new Operation\MarkAliasUninstalledOperation($package, $reason);
    }
}
