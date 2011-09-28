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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Downloader\DownloaderInterface;

/**
 * Downloaders manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class DownloadManager
{
    private $preferSource = false;
    private $downloaders  = array();

    /**
     * Initializes download manager.
     *
     * @param   Boolean $preferSource   prefer downloading from source
     */
    public function __construct($preferSource = false)
    {
        $this->preferSource = $preferSource;
    }

    /**
     * Makes downloader prefer source installation over the dist.
     *
     * @param   Boolean $preferSource   prefer downloading from source
     */
    public function preferSource($preferSource = true)
    {
        $this->preferSource = $preferSource;
    }

    /**
     * Sets installer downloader for a specific installation type.
     *
     * @param   string              $type       installation type
     * @param   DownloaderInterface $downloader downloader instance
     */
    public function setDownloader($type, DownloaderInterface $downloader)
    {
        $this->downloaders[$type] = $downloader;
    }

    /**
     * Returns downloader for a specific installation type.
     *
     * @param   string  $type   installation type
     *
     * @return  DownloaderInterface
     *
     * @throws  UnexpectedValueException    if downloader for provided type is not registeterd
     */
    public function getDownloader($type)
    {
        if (!isset($this->downloaders[$type])) {
            throw new \InvalidArgumentException('Unknown source type: '.$type);
        }

        return $this->downloaders[$type];
    }

    /**
     * Downloads package into target dir.
     *
     * @param   PackageInterface    $package        package instance
     * @param   string              $targetDir      target dir
     * @param   Boolean             $preferSource   prefer installation from source
     *
     * @throws  InvalidArgumentException            if package have no urls to download from
     */
    public function download(PackageInterface $package, $targetDir, $preferSource = null)
    {
        $preferSource = null !== $preferSource ? $preferSource : $this->preferSource;
        $sourceType   = $package->getSourceType();
        $distType     = $package->getDistType();

        if (!($preferSource && $sourceType) && $distType) {
            $downloader = $this->getDownloader($distType);
            $package->setInstallationSource('dist');
            $downloader->distDownload($package, $targetDir);

            return;
        }

        if ($sourceType) {
            $downloader = $this->getDownloader($sourceType);
            $package->setInstallationSource('source');
            $downloader->sourceDownload($package, $targetDir);

            return;
        }

        throw new \InvalidArgumentException('Package should have dist or source specified');
    }

    /**
     * Updates package from initial to target version.
     *
     * @param   PackageInterface    $initial    initial package version
     * @param   PackageInterface    $target     target package version
     * @param   string              $targetDir  target dir
     *
     * @throws  InvalidArgumentException        if initial package is not installed
     */
    public function update(PackageInterface $initial, PackageInterface $target, $targetDir)
    {
        if (null === $installationType = $initial->getInstallationSource()) {
            throw new \InvalidArgumentException(
                'Package '.$initial.' was not been installed propertly and can not be updated'
            );
        }

        $useSource = 'source' === $installationType;

        if (!$useSource) {
            $initialType = $initial->getDistType();
            $targetType  = $target->getDistType();
        } else {
            $initialType = $initial->getSourceType();
            $targetType  = $target->getSourceType();
        }

        $downloader = $this->getDownloader($initialType);

        if ($initialType === $targetType) {
            $target->setInstallationSource($installationType);
            $method = $useSource ? 'sourceUpdate' : 'distUpdate';
            $downloader->$method($initial, $target, $targetDir);
        } else {
            $downloader->remove($initial, $targetDir);
            $this->download($target, $targetDir, $useSource);
        }
    }

    /**
     * Removes package from target dir.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $targetDir  target dir
     */
    public function remove(PackageInterface $package, $targetDir)
    {
        if (null === $installationType = $package->getInstallationSource()) {
            throw new \InvalidArgumentException(
                'Package '.$package.' was not been installed propertly and can not be removed'
            );
        }

        $useSource = 'source' === $installationType;
        $downloaderType = $useSource ? $package->getSourceType() : $package->getDistType();
        $this->getDownloader($downloaderType)->remove($package, $targetDir);
    }
}
