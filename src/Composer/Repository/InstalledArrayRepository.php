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

use Composer\Package\PackageInterface;

/**
 * Installed array repository.
 *
 * This is used for serving the RootPackage inside an in-memory InstalledRepository
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstalledArrayRepository extends WritableArrayRepository implements InstalledRepositoryInterface
{
    private $installPaths = array();

    /**
     * {@inheritdoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        if (!$this->hasPackage($package)) {
            throw new \InvalidArgumentException('The package "'.$package.'" is not in the repository.');
        }

        $packageId = $package->getUniqueName();

        return isset($this->installPaths[$packageId]) ? $this->installPaths[$packageId] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function setInstallPath(PackageInterface $package, $path)
    {
        if (!$this->hasPackage($package)) {
            throw new \InvalidArgumentException('The package "'.$package.'" is not in the repository.');
        }

        $this->installPaths[$package->getUniqueName()] = $path;
    }

    /**
     * {@inheritdoc}
     */
    public function removePackage(PackageInterface $package)
    {
        $packageId = $package->getUniqueName();
        unset($this->installPaths[$packageId]);

        return parent::removePackage($package);
    }
}
