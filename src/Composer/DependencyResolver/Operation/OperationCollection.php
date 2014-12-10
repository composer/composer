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
     * @var array The operations sorted by type
     */
    private $operations = array(
        'install' => array(),
        'update' => array(),
        'uninstall' => array(),
        'plugin' => array(),
        'markAliasInstalled' => array(),
        'markAliasUninstalled' => array(),
    );

    /**
     * @return array
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->sortOperationsToArray());
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return $this->sortOperationsToArray();
    }
    /**
     * Add an operation to the collection.
     *
     * @param OperationInterface $operation
     * @throws \InvalidArgumentException if the operation type is not known.
     */
    public function add(OperationInterface $operation)
    {

        if ($operation instanceof InstallOperation || $operation instanceof UpdateOperation) {
            if ($this->operationNeedsToMoveUp($operation)) {
                $this->operations['plugin'][] = $operation;
            }
            elseif ($operation instanceof InstallOperation) {
                $this->operations['install'][] = $operation;
            }
            else {
                $this->operations['update'][] = $operation;
            }
        }
        elseif ($operation instanceof UninstallOperation) {
            $this->operations['uninstall'][] = $operation;
        }
        elseif ($operation instanceof MarkAliasInstalledOperation) {
            $this->operations['markAliasInstalled'][] = $operation;
        }
        elseif ($operation instanceof MarkAliasUninstalledOperation) {
            $this->operations['markAliasUninstalled'][] = $operation;
        }
        else {
            throw new \InvalidArgumentException('Unknown operation type.');
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
        $package = $operation->getPackage();

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
     * @return array A sorted array of operation objects
     */
    private function sortOperationsToArray()
    {
        return array_merge(
            $this->operations['uninstall'],
            $this->operations['plugin'],
            $this->operations['markAliasUninstalled'],
            $this->operations['install'],
            $this->operations['markAliasInstalled'],
            $this->operations['update']
        );
    }
}