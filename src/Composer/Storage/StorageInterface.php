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

namespace Composer\Storage;

use Composer\Package\PackageInterface;

/**
 * Storage for packages
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
interface StorageInterface
{
    /**
     * Put a package in the storage
     *
     * @param PackageInterface $package
     * @param string           $sourceDir
     *
     * @return PackageDistribution
     * @throws \RuntimeException If some storage related problems occurred
     */
    public function storePackage(PackageInterface $package, $sourceDir);

    /**
     * Get a package from the storage
     *
     * @param PackageInterface $package
     *
     * @return PackageDistribution|null If package is not found null is returned
     */
    public function retrievePackage(PackageInterface $package);
}
