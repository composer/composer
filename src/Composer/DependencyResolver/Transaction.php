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

use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\DependencyResolver\Operation\OperationInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @internal
 */
class Transaction
{
    /**
     * @var OperationInterface[]
     */
    protected $operations;

    /**
     * Packages present at the beginning of the transaction
     * @var PackageInterface[]
     */
    protected $presentPackages;

    /**
     * Package set resulting from this transaction
     * @var array<string, PackageInterface>
     */
    protected $resultPackageMap;

    /**
     * @var array<string, PackageInterface[]>
     */
    protected $resultPackagesByName = array();

    /**
     * @param PackageInterface[] $presentPackages
     * @param PackageInterface[] $resultPackages
     */
    public function __construct(array $presentPackages, array $resultPackages)
    {
        $this->presentPackages = $presentPackages;
        $this->setResultPackageMaps($resultPackages);
        $this->operations = $this->calculateOperations();
    }

    /**
     * @return OperationInterface[]
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /**
     * @param PackageInterface[] $resultPackages
     * @return void
     */
    private function setResultPackageMaps(array $resultPackages): void
    {
        $packageSort = static function (PackageInterface $a, PackageInterface $b): int {
            // sort alias packages by the same name behind their non alias version
            if ($a->getName() == $b->getName()) {
                if ($a instanceof AliasPackage != $b instanceof AliasPackage) {
                    return $a instanceof AliasPackage ? -1 : 1;
                }
                // if names are the same, compare version, e.g. to sort aliases reliably, actual order does not matter
                return strcmp($b->getVersion(), $a->getVersion());
            }

            return strcmp($b->getName(), $a->getName());
        };

        $this->resultPackageMap = array();
        foreach ($resultPackages as $package) {
            $this->resultPackageMap[spl_object_hash($package)] = $package;
            foreach ($package->getNames() as $name) {
                $this->resultPackagesByName[$name][] = $package;
            }
        }

        uasort($this->resultPackageMap, $packageSort);
        foreach ($this->resultPackagesByName as $name => $packages) {
            uasort($this->resultPackagesByName[$name], $packageSort);
        }
    }

    /**
     * @return OperationInterface[]
     */
    protected function calculateOperations(): array
    {
        $operations = array();

        $presentPackageMap = array();
        $removeMap = array();
        $presentAliasMap = array();
        $removeAliasMap = array();
        foreach ($this->presentPackages as $package) {
            if ($package instanceof AliasPackage) {
                $presentAliasMap[$package->getName().'::'.$package->getVersion()] = $package;
                $removeAliasMap[$package->getName().'::'.$package->getVersion()] = $package;
            } else {
                $presentPackageMap[$package->getName()] = $package;
                $removeMap[$package->getName()] = $package;
            }
        }

        $stack = $this->getRootPackages();

        $visited = array();
        $processed = array();

        while (!empty($stack)) {
            $package = array_pop($stack);

            if (isset($processed[spl_object_hash($package)])) {
                continue;
            }

            if (!isset($visited[spl_object_hash($package)])) {
                $visited[spl_object_hash($package)] = true;

                $stack[] = $package;
                if ($package instanceof AliasPackage) {
                    $stack[] = $package->getAliasOf();
                } else {
                    foreach ($package->getRequires() as $link) {
                        $possibleRequires = $this->getProvidersInResult($link);

                        foreach ($possibleRequires as $require) {
                            $stack[] = $require;
                        }
                    }
                }
            } elseif (!isset($processed[spl_object_hash($package)])) {
                $processed[spl_object_hash($package)] = true;

                if ($package instanceof AliasPackage) {
                    $aliasKey = $package->getName().'::'.$package->getVersion();
                    if (isset($presentAliasMap[$aliasKey])) {
                        unset($removeAliasMap[$aliasKey]);
                    } else {
                        $operations[] = new Operation\MarkAliasInstalledOperation($package);
                    }
                } else {
                    if (isset($presentPackageMap[$package->getName()])) {
                        $source = $presentPackageMap[$package->getName()];

                        // do we need to update?
                        // TODO different for lock?
                        if ($package->getVersion() != $presentPackageMap[$package->getName()]->getVersion() ||
                            $package->getDistReference() !== $presentPackageMap[$package->getName()]->getDistReference() ||
                            $package->getSourceReference() !== $presentPackageMap[$package->getName()]->getSourceReference()
                        ) {
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
            array_unshift($operations, new Operation\UninstallOperation($package));
        }
        foreach ($removeAliasMap as $nameVersion => $package) {
            $operations[] = new Operation\MarkAliasUninstalledOperation($package);
        }

        $operations = $this->movePluginsToFront($operations);
        // TODO fix this:
        // we have to do this again here even though the above stack code did it because moving plugins moves them before uninstalls
        $operations = $this->moveUninstallsToFront($operations);

        // TODO skip updates which don't update? is this needed? we shouldn't schedule this update in the first place?
        /*
        if ('update' === $opType) {
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

        return $this->operations = $operations;
    }

    /**
     * Determine which packages in the result are not required by any other packages in it.
     *
     * These serve as a starting point to enumerate packages in a topological order despite potential cycles.
     * If there are packages with a cycle on the top level the package with the lowest name gets picked
     *
     * @return array<string, PackageInterface>
     */
    protected function getRootPackages(): array
    {
        $roots = $this->resultPackageMap;

        foreach ($this->resultPackageMap as $packageHash => $package) {
            if (!isset($roots[$packageHash])) {
                continue;
            }

            foreach ($package->getRequires() as $link) {
                $possibleRequires = $this->getProvidersInResult($link);

                foreach ($possibleRequires as $require) {
                    if ($require !== $package) {
                        unset($roots[spl_object_hash($require)]);
                    }
                }
            }
        }

        return $roots;
    }

    /**
     * @return PackageInterface[]
     */
    protected function getProvidersInResult(Link $link): array
    {
        if (!isset($this->resultPackagesByName[$link->getTarget()])) {
            return array();
        }

        return $this->resultPackagesByName[$link->getTarget()];
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
     * @param  OperationInterface[] $operations
     * @return OperationInterface[] reordered operation list
     */
    private function movePluginsToFront(array $operations): array
    {
        $dlModifyingPluginsNoDeps = array();
        $dlModifyingPluginsWithDeps = array();
        $dlModifyingPluginRequires = array();
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

            $isDownloadsModifyingPlugin = $package->getType() === 'composer-plugin' && ($extra = $package->getExtra()) && isset($extra['plugin-modifies-downloads']) && $extra['plugin-modifies-downloads'] === true;

            // is this a downloads modifying plugin or a dependency of one?
            if ($isDownloadsModifyingPlugin || count(array_intersect($package->getNames(), $dlModifyingPluginRequires))) {
                // get the package's requires, but filter out any platform requirements
                $requires = array_filter(array_keys($package->getRequires()), static function ($req): bool {
                    return !PlatformRepository::isPlatformPackage($req);
                });

                // is this a plugin with no meaningful dependencies?
                if ($isDownloadsModifyingPlugin && !count($requires)) {
                    // plugins with no dependencies go to the very front
                    array_unshift($dlModifyingPluginsNoDeps, $op);
                } else {
                    // capture the requirements for this package so those packages will be moved up as well
                    $dlModifyingPluginRequires = array_merge($dlModifyingPluginRequires, $requires);
                    // move the operation to the front
                    array_unshift($dlModifyingPluginsWithDeps, $op);
                }

                unset($operations[$idx]);
                continue;
            }

            // is this package a plugin?
            $isPlugin = $package->getType() === 'composer-plugin' || $package->getType() === 'composer-installer';

            // is this a plugin or a dependency of a plugin?
            if ($isPlugin || count(array_intersect($package->getNames(), $pluginRequires))) {
                // get the package's requires, but filter out any platform requirements
                $requires = array_filter(array_keys($package->getRequires()), static function ($req): bool {
                    return !PlatformRepository::isPlatformPackage($req);
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

        return array_merge($dlModifyingPluginsNoDeps, $dlModifyingPluginsWithDeps, $pluginsNoDeps, $pluginsWithDeps, $operations);
    }

    /**
     * Removals of packages should be executed before installations in
     * case two packages resolve to the same path (due to custom installers)
     *
     * @param  OperationInterface[] $operations
     * @return OperationInterface[] reordered operation list
     */
    private function moveUninstallsToFront(array $operations): array
    {
        $uninstOps = array();
        foreach ($operations as $idx => $op) {
            if ($op instanceof Operation\UninstallOperation || $op instanceof Operation\MarkAliasUninstalledOperation) {
                $uninstOps[] = $op;
                unset($operations[$idx]);
            }
        }

        return array_merge($uninstOps, $operations);
    }
}
