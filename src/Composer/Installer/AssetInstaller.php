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

namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Downloader\DownloadManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Package\PackageInterface;

/**
 * Asset installation manager.
 *
 * The asset installation manager functions identical to the LibraryInstaller
 * with one exception:
 *
 *     If the 'extra' section in the package definition contains an element
 *     'asset-dir' then the installation location will be changed to
 *     'asset-dir'+TargetDir instead of VendorDir+PackageName+TargetDir.
 *
 * Should the asset-dir be omitted then this installer will function and as such
 * function identical the 'library' type.
 *
 * @author Mike van Riel <mike.vanriel@naenius.com>
 */
class AssetInstaller extends LibraryInstaller
{
    /**
     * Initializes asset installer.
     *
     * @param string                      $vendorDir  relative path for packages home
     * @param string                      $binDir     relative path for binaries
     * @param DownloadManager             $dm         download manager
     * @param WritableRepositoryInterface $repository repository controller
     * @param IOInterface                 $io         io instance
     *
     * @see self::getInstallPath() for the determination of the vendor and bin path.
     * @see \Composer\Factory::createInstallationManager for the instantiation.
     */
    public function __construct(
        $vendorDir, $binDir, DownloadManager $dm,
        WritableRepositoryInterface $repository, IOInterface $io
    ) {
        parent::__construct($vendorDir, $binDir, $dm, $repository, $io, 'asset');
    }

    /**
     * Returns the asset-dir + target dir if asset-dir is provided in the extra.
     *
     * @param PackageInterface $package Package instance.
     *
     * @return string
     */
    public function getInstallPath(PackageInterface $package)
    {
        $extra = $package->getExtra();
        if (isset($extra['asset-dir'])) {
            $this->vendorDir = $extra['asset-dir'];
            $this->initializeVendorDir();

            $targetDir = $package->getTargetDir();
            return ($this->vendorDir ? $this->vendorDir : '')
                . ($targetDir ? '/' . $targetDir : '');
        }

        return parent::getInstallPath($package);
    }
}