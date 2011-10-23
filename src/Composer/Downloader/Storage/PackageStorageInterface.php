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

namespace Composer\Downloader\Storage;

use \Composer\Package\PackageInterface;

/**
 * Storage for packages
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
interface PackageStorageInterface
{
    /**
     * Put a package in the storage
     *
     * @param PackageInterface $package
     * @param string           $targetDir
     *
     * @return PackageInterface|null Stored package or null if package was not stored
     * @throws \RuntimeException If some storage related problems occurred
     */
    public function storePackage(PackageInterface $package, $targetDir);
}
