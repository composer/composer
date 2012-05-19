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
    protected $decisionMap;
    protected $decisionQueue;
    protected $decisionQueueWhy;

    public function __construct($policy, $pool, $installedMap, $decisionMap, array $decisionQueue, $decisionQueueWhy)
    {
        $this->policy = $policy;
        $this->pool = $pool;
        $this->installedMap = $installedMap;
        $this->decisionMap = $decisionMap;
        $this->decisionQueue = $decisionQueue;
        $this->decisionQueueWhy = $decisionQueueWhy;
    }

    public function getOperations()
    {
        $transaction = array();
        $installMeansUpdateMap = array();

        foreach ($this->decisionQueue as $i => $literal) {
            $package = $this->pool->literalToPackage($literal);

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

        foreach ($this->decisionQueue as $i => $literal) {
            $package = $this->pool->literalToPackage($literal);

            // wanted & installed || !wanted & !installed
            if (($literal > 0) == (isset($this->installedMap[$package->getId()]))) {
                continue;
            }

            if ($literal > 0) {
                if ($package instanceof AliasPackage) {
                    $transaction[] = new Operation\MarkAliasInstalledOperation(
                        $package, $this->decisionQueueWhy[$i]
                    );
                    continue;
                }

                if (isset($installMeansUpdateMap[abs($literal)])) {

                    $source = $installMeansUpdateMap[abs($literal)];

                    $transaction[] = new Operation\UpdateOperation(
                        $source, $package, $this->decisionQueueWhy[$i]
                    );

                    // avoid updates to one package from multiple origins
                    unset($installMeansUpdateMap[abs($literal)]);
                    $ignoreRemove[$source->getId()] = true;
                } else {
                    $transaction[] = new Operation\InstallOperation(
                        $package, $this->decisionQueueWhy[$i]
                    );
                }
            } else if (!isset($ignoreRemove[$package->getId()])) {
                if ($package instanceof AliasPackage) {
                    $transaction[] = new Operation\MarkAliasInstalledOperation(
                        $package, $this->decisionQueueWhy[$i]
                    );
                } else {
                    $transaction[] = new Operation\UninstallOperation(
                        $package, $this->decisionQueueWhy[$i]
                    );
                }
            }
        }

        $allDecidedMap = $this->decisionMap;
        foreach ($this->decisionMap as $packageId => $decision) {
            if ($decision != 0) {
                $package = $this->pool->packageById($packageId);
                if ($package instanceof AliasPackage) {
                    $allDecidedMap[$package->getAliasOf()->getId()] = $decision;
                }
            }
        }

        foreach ($allDecidedMap as $packageId => $decision) {
            if ($packageId === 0) {
                continue;
            }

            if (0 == $decision && isset($this->installedMap[$packageId])) {
                $package = $this->pool->packageById($packageId);

                if ($package instanceof AliasPackage) {
                    $transaction[] = new Operation\MarkAliasInstalledOperation(
                        $package, null
                    );
                } else {
                    $transaction[] = new Operation\UninstallOperation(
                        $package, null
                    );
                }

                $this->decisionMap[$packageId] = -1;
            }
        }

        foreach ($this->decisionMap as $packageId => $decision) {
            if ($packageId === 0) {
                continue;
            }

            if (0 == $decision && isset($this->installedMap[$packageId])) {
                $package = $this->pool->packageById($packageId);

                if ($package instanceof AliasPackage) {
                    $transaction[] = new Operation\MarkAliasInstalledOperation(
                        $package, null
                    );
                } else {
                    $transaction[] = new Operation\UninstallOperation(
                        $package, null
                    );
                }

                $this->decisionMap[$packageId] = -1;
            }
        }

        return array_reverse($transaction);
    }
}
