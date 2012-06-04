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
        $installMeansUpdateMap = array();

        foreach ($this->installedMap as $packageId => $void) {
            if ($this->decisions->undecided($packageId)) {
                $this->decisions->decide(-$packageId, 1, null);
            }
        }

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
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

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $package = $this->pool->literalToPackage($literal);

            // wanted & installed || !wanted & !installed
            if (($literal > 0) == (isset($this->installedMap[$package->getId()]))) {
                continue;
            }

            if ($literal > 0) {
                if ($package instanceof AliasPackage) {
                    $this->markAliasInstalled($package, $decision[Decisions::DECISION_REASON]);
                    continue;
                }

                if (isset($installMeansUpdateMap[abs($literal)])) {

                    $source = $installMeansUpdateMap[abs($literal)];

                    $this->update($source, $package, $decision[Decisions::DECISION_REASON]);

                    // avoid updates to one package from multiple origins
                    unset($installMeansUpdateMap[abs($literal)]);
                    $ignoreRemove[$source->getId()] = true;
                } else {
                    $this->install($package, $decision[Decisions::DECISION_REASON]);
                }
            }
        }

        foreach ($this->decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $package = $this->pool->literalToPackage($literal);

            // wanted & installed || !wanted & !installed
            if (($literal > 0) == (isset($this->installedMap[$package->getId()]))) {
                continue;
            }

            if ($literal <= 0 && !isset($ignoreRemove[$package->getId()])) {
                $this->uninstall($package, $decision[Decisions::DECISION_REASON]);
            }
        }

        return $this->transaction;
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
