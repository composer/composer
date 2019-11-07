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
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class LocalRepoTransaction
{
    /** @var array */
    protected $lockedPackages;
    protected $lockedPackagesByName = array();

    /** @var RepositoryInterface */
    protected $localRepository;

    /** @var array */
    protected $operations;

    /**
     * Reassigns ids for all packages in the lockedrepository
     */
    public function __construct(RepositoryInterface $lockedRepository, $localRepository)
    {
        $this->localRepository = $localRepository;
        $this->setLockedPackageMaps($lockedRepository);
        $this->operations = $this->calculateOperations();
    }

    private function setLockedPackageMaps($lockedRepository)
    {
        $packageSort = function (PackageInterface $a, PackageInterface $b) {
            // sort alias packages by the same name behind their non alias version
            if ($a->getName() == $b->getName() && $a instanceof AliasPackage != $b instanceof AliasPackage) {
                return $a instanceof AliasPackage ? -1 : 1;
            }
            return strcmp($b->getName(), $a->getName());
        };

        $id = 1;
        $this->lockedPackages = array();
        foreach ($lockedRepository->getPackages() as $package) {
            $package->id = $id++;
            $this->lockedPackages[$package->id] = $package;
            foreach ($package->getNames() as $name) {
                $this->lockedPackagesByName[$name][] = $package;
            }
        }

        uasort($this->lockedPackages, $packageSort);
        foreach ($this->lockedPackagesByName as $name => $packages) {
            uasort($this->lockedPackagesByName[$name], $packageSort);
        }
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
        $localAliasMap = array();
        $removeAliasMap = array();
        foreach ($this->localRepository->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                $localAliasMap[$package->getName().'::'.$package->getVersion()] = $package;
                $removeAliasMap[$package->getName().'::'.$package->getVersion()] = $package;
            } else {
                $localPackageMap[$package->getName()] = $package;
                $removeMap[$package->getName()] = $package;
            }
        }

        $stack = $this->getRootPackages();

        $visited = array();
        $processed = array();

        while (!empty($stack)) {
            $package = array_pop($stack);

            if (isset($processed[$package->id])) {
                continue;
            }

            if (!isset($visited[$package->id])) {
                $visited[$package->id] = true;

                $stack[] = $package;
                if ($package instanceof AliasPackage) {
                    $stack[] = $package->getAliasOf();
                } else {
                    foreach ($package->getRequires() as $link) {
                        $possibleRequires = $this->getLockedProviders($link);

                        foreach ($possibleRequires as $require) {
                            $stack[] = $require;
                        }
                    }
                }
            } elseif (!isset($processed[$package->id])) {
                $processed[$package->id] = true;

                if ($package instanceof AliasPackage) {
                    $aliasKey = $package->getName().'::'.$package->getVersion();
                    if (isset($localAliasMap[$aliasKey])) {
                        unset($removeAliasMap[$aliasKey]);
                    } else {
                        $operations[] = new Operation\MarkAliasInstalledOperation($package);
                    }
                } else {
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
                }
            }
        }

        foreach ($removeMap as $name => $package) {
            array_unshift($operations, new Operation\UninstallOperation($package, null));
        }
        foreach ($removeAliasMap as $nameVersion => $package) {
            $operations[] = new Operation\MarkAliasUninstalledOperation($package, null);
        }

        $operations = $this->movePluginsToFront($operations);
        // TODO fix this:
        // we have to do this again here even though the above stack code did it because moving plugins moves them before uninstalls
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
     * Determine which packages in the lock file are not required by any other packages in the lock file.
     *
     * These serve as a starting point to enumerate packages in a topological order despite potential cycles.
     * If there are packages with a cycle on the top level the package with the lowest name gets picked
     *
     * @return array
     */
    private function getRootPackages()
    {
        $roots = $this->lockedPackages;

        foreach ($this->lockedPackages as $packageId => $package) {
            if (!isset($roots[$packageId])) {
                continue;
            }

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->getLockedProviders($link);

                foreach ($possibleRequires as $require) {
                    if ($require !== $package) {
                        unset($roots[$require->id]);
                    }
                }
            }
        }

        return $roots;
    }

    private function getLockedProviders(Link $link)
    {
        if (!isset($this->lockedPackagesByName[$link->getTarget()])) {
            return array();
        }
        return $this->lockedPackagesByName[$link->getTarget()];
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
            if ($op instanceof UninstallOperation) {
                $uninstOps[] = $op;
                unset($operations[$idx]);
            }
        }

        return array_merge($uninstOps, $operations);
    }
}
