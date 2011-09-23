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
            throw new \UnexpectedValueException('Unknown source type: '.$type);
        }

        return $this->downloaders[$type];
    }

    /**
     * Downloads package into target dir.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $targetDir  target dir
     *
     * @return  string                          downloader type (source/dist)
     *
     * @throws  InvalidArgumentException        if package have no urls to download from
     */
    public function download(PackageInterface $package, $targetDir)
    {
        $sourceType = $package->getSourceType();
        $distType   = $package->getDistType();

        if (!($this->preferSource && $sourceType) && $distType) {
            $downloader = $this->getDownloader($distType);
            $downloader->download(
                $package, $targetDir, $package->getDistUrl(), $package->getDistSha1Checksum()
            );

            return 'dist';
        }

        if ($sourceType) {
            $downloader = $this->getDownloader($sourceType);
            $downloader->download($package, $targetDir, $package->getSourceUrl());

            return 'source';
        }

        throw new \InvalidArgumentException('Package should have dist or source specified');
    }

    /**
     * Updates package from initial to target version.
     *
     * @param   PackageInterface    $initial    initial package version
     * @param   PackageInterface    $target     target package version
     * @param   string              $targetDir  target dir
     * @param   string              $type       downloader type (source/dist)
     *
     * @throws  InvalidArgumentException        if initial package is not installed
     */
    public function update(PackageInterface $initial, PackageInterface $target, $targetDir, $type)
    {
        if ('dist' === $type) {
            $downloader = $this->getDownloader($initial->getDistType());
            $downloader->update($initial, $target, $targetDir);
        } elseif ('source' === $type) {
            $downloader = $this->getDownloader($initial->getSourceType());
            $downloader->update($initial, $target, $targetDir);
        } else {
            throw new \InvalidArgumentException('Package should have dist or source specified');
        }
    }

    /**
     * Removes package from target dir.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $targetDir  target dir
     * @param   string              $type       downloader type (source/dist)
     */
    public function remove(PackageInterface $package, $targetDir, $type)
    {
        if ('dist' === $type) {
            $downloader = $this->getDownloader($package->getDistType());
            $downloader->remove($package, $targetDir);
        } elseif ('source' === $type) {
            $downloader = $this->getDownloader($package->getSourceType());
            $downloader->remove($package, $targetDir);
        } else {
            throw new \InvalidArgumentException('Package should have dist or source specified');
        }
    }
}
