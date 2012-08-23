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

namespace Composer\Repository;

use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface StreamableRepositoryInterface extends RepositoryInterface
{
    /**
     * Return partial package data without loading them all to save on memory
     *
     * The function must return an array of package arrays.
     *
     * The package array must contain the following fields:
     *  - name: package name (normalized/lowercased)
     *  - repo: reference to the repository instance
     *  - version: normalized version
     *  - replace: array of package name => version constraint, optional
     *  - provide: array of package name => version constraint, optional
     *  - alias: pretty alias that this package should be aliased to, optional
     *  - alias_normalized: normalized alias that this package should be aliased to, optional
     *
     * Any additional information can be returned and will be sent back
     * into loadPackage/loadAliasPackage for completing the package loading
     * when it's needed.
     *
     * @return array
     */
    public function getMinimalPackages();

    /**
     * Loads a package from minimal info of the package
     *
     * @param  array            $data the minimal info as was returned by getMinimalPackage
     * @return PackageInterface
     */
    public function loadPackage(array $data);

    /**
     * Loads an alias package from minimal info of the package
     *
     * @param  array            $data    the minimal info as was returned by getMinimalPackage
     * @param  PackageInterface $aliasOf the package which this alias is an alias of
     * @return AliasPackage
     */
    public function loadAliasPackage(array $data, PackageInterface $aliasOf);
}
