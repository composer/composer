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

use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Package\AliasPackage;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class LocalRepoTransaction
{
    /** @var RepositoryInterface */
    protected $lockedRepository;

    /** @var RepositoryInterface */
    protected $localRepository;

    public function __construct($lockedRepository, $localRepository)
    {
        $this->lockedRepository = $lockedRepository;
        $this->localRepository = $localRepository;

        $this->operations = $this->calculateOperations();
    }

    public function getOperations()
    {
        return $this->operations;
    }

    protected function calculateOperations()
    {
        $operations = array();

        $localPackageMap = array();
        $removeMap = array();
        foreach ($this->localRepository->getPackages() as $package) {
            if (isset($localPackageMap[$package->getName()])) {
                die("Alias?");
            }
            $localPackageMap[$package->getName()] = $package;
            $removeMap[$package->getName()] = $package;
        }

        $lockedPackages = array();
        foreach ($this->lockedRepository->getPackages() as $package) {
            if (isset($localPackageMap[$package->getName()])) {
                $source = $localPackageMap[$package->getName()];

                // do we need to update?
                if ($package->getVersion() != $localPackageMap[$package->getName()]->getVersion()) {
                    $operations[] = new Operation\UpdateOperation($source, $package);
                } elseif ($package->isDev() && $package->getSourceReference() !== $localPackageMap[$package->getName()]->getSourceReference()) {
                    $operations[] = new Operation\UpdateOperation($source, $package);
                }
                unset($removeMap[$package->getName()]);
            } else {
                $operations[] = new Operation\InstallOperation($package);
                unset($removeMap[$package->getName()]);
            }


/*
            if (isset($lockedPackages[$package->getName()])) {
                die("Alias?");
            }
            $lockedPackages[$package->getName()] = $package;*/
        }

        foreach ($removeMap as $name => $package) {
            $operations[] = new Operation\UninstallOperation($package, null);
        }

        $operations = $this->movePluginsToFront($operations);
        $operations = $this->moveUninstallsToFront($operations);

        // TODO skip updates which don't update? is this needed? we shouldn't schedule this update in the first place?
        /*
        if ('update' === $jobType) {
            $targetPackage = $operation->getTargetPackage();
            if ($targetPackage->isDev()) {
                $initialPackage = $operation->getInitialPackage();
                if ($targetPackage->getVersion() === $initialPackage->getVersion()
                    && (!$targetPackage->getSourceReference() || $targetPackage->getSourceReference() === $initialPackage->getSourceReference())
                    && (!$targetPackage->getDistReference() || $targetPackage->getDistReference() === $initialPackage->getDistReference())
                ) {
                    $this->io->writeError('  - Skipping update of ' . $targetPackage->getPrettyName() . ' to the same reference-locked version', true, IOInterface::DEBUG);
                    $this->io->writeError('', true, IOInterface::DEBUG);

                    continue;
                }
            }
        }*/

        return $operations;
    }

    /**
     * Workaround: if your packages depend on plugins, we must be sure
     * that those are installed / updated first; else it would lead to packages
     * being installed multiple times in different folders, when running Composer
     * twice.
     *
     * While this does not fix the root-causes of https://github.com/composer/composer/issues/1147,
     * it at least fixes the symptoms and makes usage of composer possible (again)
     * in such scenarios.
     *
     * @param  Operation\OperationInterface[] $operations
     * @return Operation\OperationInterface[] reordered operation list
     */
    private function movePluginsToFront(array $operations)
    {
        $pluginsNoDeps = array();
        $pluginsWithDeps = array();
        $pluginRequires = array();

        foreach (array_reverse($operations, true) as $idx => $op) {
            if ($op instanceof Operation\InstallOperation) {
                $package = $op->getPackage();
            } elseif ($op instanceof Operation\UpdateOperation) {
                $package = $op->getTargetPackage();
            } else {
                continue;
            }

            // is this package a plugin?
            $isPlugin = $package->getType() === 'composer-plugin' || $package->getType() === 'composer-installer';

            // is this a plugin or a dependency of a plugin?
            if ($isPlugin || count(array_intersect($package->getNames(), $pluginRequires))) {
                // get the package's requires, but filter out any platform requirements or 'composer-plugin-api'
                $requires = array_filter(array_keys($package->getRequires()), function ($req) {
                    return $req !== 'composer-plugin-api' && !preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $req);
                });

                // is this a plugin with no meaningful dependencies?
                if ($isPlugin && !count($requires)) {
                    // plugins with no dependencies go to the very front
                    array_unshift($pluginsNoDeps, $op);
                } else {
                    // capture the requirements for this package so those packages will be moved up as well
                    $pluginRequires = array_merge($pluginRequires, $requires);
                    // move the operation to the front
                    array_unshift($pluginsWithDeps, $op);
                }

                unset($operations[$idx]);
            }
        }

        return array_merge($pluginsNoDeps, $pluginsWithDeps, $operations);
    }

    /**
     * Removals of packages should be executed before installations in
     * case two packages resolve to the same path (due to custom installers)
     *
     * @param  Operation\OperationInterface[] $operations
     * @return Operation\OperationInterface[] reordered operation list
     */
    private function moveUninstallsToFront(array $operations)
    {
        $uninstOps = array();
        foreach ($operations as $idx => $op) {
            if ($op instanceof UninstallOperation || $op instanceof MarkAliasUninstalledOperation) {
                $uninstOps[] = $op;
                unset($operations[$idx]);
            }
        }

        return array_merge($uninstOps, $operations);
    }
}
