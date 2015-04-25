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

namespace Composer\DependencyResolver\Operation;

use Composer\Repository\PlatformRepository;

/**
 * Collection class for the different operation types
 *
 * @author Chad Sikorra <chad.sikorra@gmail.com>
 */
class OperationCollection implements \IteratorAggregate
{
    /**
     * The 'plugin' operation type that must be executed before all others.
     */
    const TYPE_PLUGIN = 'plugin';

    /**
     * @var SolverOperation[] The operations, minus the uninstalls and plugins.
     */
    private $operations = array();

    /**
     * @var array The operations by type so they can be selectively retrieved.
     */
    private $operationTypes = array(
        self::TYPE_PLUGIN => array(),
        UninstallOperation::TYPE => array(),
        UpdateOperation::TYPE => array(),
        InstallOperation::TYPE => array(),
        MarkAliasUninstalledOperation::TYPE => array(),
        MarkAliasInstalledOperation::TYPE => array(),
    );

    /**
     * @return SolverOperation[]
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->sortOperationsToArray());
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return !array_filter($this->sortOperationsToArray());
    }

    /**
     * @return InstallOperation[] The install operations.
     */
    public function getInstalls()
    {
        return $this->operationTypes[InstallOperation::TYPE];
    }

    /**
     * @return UpdateOperation[] The update operations.
     */
    public function getUpdates()
    {
        return $this->operationTypes[UpdateOperation::TYPE];
    }

    /**
     * @return UninstallOperation[] The uninstall operations.
     */
    public function getUninstalls()
    {
        return $this->operationTypes[UninstallOperation::TYPE];
    }

    /**
     * @return SolverOperation[] The plugin operations.
     */
    public function getPlugins()
    {
        return $this->operationTypes[self::TYPE_PLUGIN];
    }

    /**
     * @return MarkAliasInstalledOperation[] The markAliasInstalled operations.
     */
    public function getMarkAliasInstalled()
    {
        return $this->operationTypes[MarkAliasInstalledOperation::TYPE];
    }

    /**
     * @return MarkAliasUninstalledOperation[] The markAliasUninstalled operations.
     */
    public function getMarkAliasUninstalled()
    {
        return $this->operationTypes[MarkAliasUninstalledOperation::TYPE];
    }

    /**
     * @return SolverOperation[]
     */
    public function toArray()
    {
        return $this->sortOperationsToArray();
    }

    /**
     * Add an operation to the collection.
     *
     * @param OperationInterface $operation
     */
    public function add(OperationInterface $operation)
    {

        $type = $operation->getJobType();
        if ($this->operationNeedsToMoveUp($operation)) {
            $type = self::TYPE_PLUGIN;
        }

        $this->operationTypes[$type][] = $operation;
        if ($type != UninstallOperation::TYPE && $type != self::TYPE_PLUGIN) {
            $this->operations[] = $operation;
        }
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
     * @param OperationInterface $operation
     * @return bool
     */
    private function operationNeedsToMoveUp(OperationInterface $operation)
    {
        if (!($operation instanceof InstallOperation || $operation instanceof UpdateOperation)) {
            return false;
        }
        $package = ($operation instanceof InstallOperation) ? $operation->getPackage() : $operation->getTargetPackage();

        if (!($package->getType() === 'composer-plugin' || $package->getType() === 'composer-installer')) {
            return false;
        }

        $requires = array_keys($package->getRequires());

        foreach ($requires as $index => $req) {
            if ($req === 'composer-plugin-api' || preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $req)) {
                unset($requires[$index]);
            }
        }

        // if there are no other requirements, the plugin should move to the top of the list
        return !count($requires);
    }

    /**
     * Sorts operations in the order they should be processed. Removals of packages should be executed before
     * installations in case two packages resolve to the same path (due to custom installers). Additionally,
     * plugin update/install operations should occur before other updates/installs.
     *
     * @return SolverOperation[] A sorted array of operation objects
     */
    private function sortOperationsToArray()
    {
        return array_merge(
            $this->operationTypes[UninstallOperation::TYPE],
            $this->operationTypes[self::TYPE_PLUGIN],
            $this->operations
        );
    }
}
