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
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/**
 * Downloaders manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class DownloadManager
{
    private $io;
    private $preferDist = false;
    private $preferSource = false;
    private $filesystem;
    private $downloaders  = array();

    /**
     * Initializes download manager.
     *
     * @param IOInterface     $io           The Input Output Interface
     * @param bool            $preferSource prefer downloading from source
     * @param Filesystem|null $filesystem   custom Filesystem object
     */
    public function __construct(IOInterface $io, $preferSource = false, Filesystem $filesystem = null)
    {
        $this->io = $io;
        $this->preferSource = $preferSource;
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    /**
     * Makes downloader prefer source installation over the dist.
     *
     * @param  bool            $preferSource prefer downloading from source
     * @return DownloadManager
     */
    public function setPreferSource($preferSource)
    {
        $this->preferSource = $preferSource;

        return $this;
    }

    /**
     * Makes downloader prefer dist installation over the source.
     *
     * @param  bool            $preferDist prefer downloading from dist
     * @return DownloadManager
     */
    public function setPreferDist($preferDist)
    {
        $this->preferDist = $preferDist;

        return $this;
    }

    /**
     * Sets whether to output download progress information for all registered
     * downloaders
     *
     * @param  bool            $outputProgress
     * @return DownloadManager
     */
    public function setOutputProgress($outputProgress)
    {
        foreach ($this->downloaders as $downloader) {
            $downloader->setOutputProgress($outputProgress);
        }

        return $this;
    }

    /**
     * Sets installer downloader for a specific installation type.
     *
     * @param  string              $type       installation type
     * @param  DownloaderInterface $downloader downloader instance
     * @return DownloadManager
     */
    public function setDownloader($type, DownloaderInterface $downloader)
    {
        $type = strtolower($type);
        $this->downloaders[$type] = $downloader;

        return $this;
    }

    /**
     * Returns downloader for a specific installation type.
     *
     * @param  string              $type installation type
     * @return DownloaderInterface
     *
     * @throws \InvalidArgumentException if downloader for provided type is not registered
     */
    public function getDownloader($type)
    {
        $type = strtolower($type);
        if (!isset($this->downloaders[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown downloader type: %s. Available types: %s.', $type, implode(', ', array_keys($this->downloaders))));
        }

        return $this->downloaders[$type];
    }

    /**
     * Returns downloader for already installed package.
     *
     * @param  PackageInterface         $package package instance
     * @return DownloaderInterface|null
     *
     * @throws \InvalidArgumentException if package has no installation source specified
     * @throws \LogicException           if specific downloader used to load package with
     *                                   wrong type
     */
    public function getDownloaderForInstalledPackage(PackageInterface $package)
    {
        $installationSource = $package->getInstallationSource();

        if ('metapackage' === $package->getType()) {
            return;
        }

        if ('dist' === $installationSource) {
            $downloader = $this->getDownloader($package->getDistType());
        } elseif ('source' === $installationSource) {
            $downloader = $this->getDownloader($package->getSourceType());
        } else {
            throw new \InvalidArgumentException(
                'Package '.$package.' seems not been installed properly'
            );
        }

        if ($installationSource !== $downloader->getInstallationSource()) {
            throw new \LogicException(sprintf(
                'Downloader "%s" is a %s type downloader and can not be used to download %s',
                get_class($downloader), $downloader->getInstallationSource(), $installationSource
            ));
        }

        return $downloader;
    }

    /**
     * Downloads package into target dir.
     *
     * @param PackageInterface $package      package instance
     * @param string           $targetDir    target dir
     * @param bool             $preferSource prefer installation from source
     *
     * @throws \InvalidArgumentException if package have no urls to download from
     * @throws \RuntimeException
     */
    public function download(PackageInterface $package, $targetDir, $preferSource = null)
    {
        $preferSource = null !== $preferSource ? $preferSource : $this->preferSource;
        $sourceType   = $package->getSourceType();
        $distType     = $package->getDistType();

        $sources = array();
        if ($sourceType) {
            $sources[] = 'source';
        }
        if ($distType) {
            $sources[] = 'dist';
        }

        if (empty($sources)) {
            throw new \InvalidArgumentException('Package '.$package.' must have a source or dist specified');
        }

        if ((!$package->isDev() || $this->preferDist) && !$preferSource) {
            $sources = array_reverse($sources);
        }

        $this->filesystem->ensureDirectoryExists($targetDir);

        foreach ($sources as $i => $source) {
            if (isset($e)) {
                $this->io->writeError('    <warning>Now trying to download from ' . $source . '</warning>');
            }
            $package->setInstallationSource($source);
            try {
                $downloader = $this->getDownloaderForInstalledPackage($package);
                if ($downloader) {
                    $downloader->download($package, $targetDir);
                }
                break;
            } catch (\RuntimeException $e) {
                if ($i === count($sources) - 1) {
                    throw $e;
                }

                $this->io->writeError(
                    '    <warning>Failed to download '.
                    $package->getPrettyName().
                    ' from ' . $source . ': '.
                    $e->getMessage().'</warning>'
                );
            }
        }
    }

    /**
     * Updates package from initial to target version.
     *
     * @param PackageInterface $initial   initial package version
     * @param PackageInterface $target    target package version
     * @param string           $targetDir target dir
     *
     * @throws \InvalidArgumentException if initial package is not installed
     */
    public function update(PackageInterface $initial, PackageInterface $target, $targetDir)
    {
        $downloader = $this->getDownloaderForInstalledPackage($initial);
        if (!$downloader) {
            return;
        }

        $installationSource = $initial->getInstallationSource();

        if ('dist' === $installationSource) {
            $initialType = $initial->getDistType();
            $targetType  = $target->getDistType();
        } else {
            $initialType = $initial->getSourceType();
            $targetType  = $target->getSourceType();
        }

        // upgrading from a dist stable package to a dev package, force source reinstall
        if ($target->isDev() && 'dist' === $installationSource) {
            $downloader->remove($initial, $targetDir);
            $this->download($target, $targetDir);

            return;
        }

        if ($initialType === $targetType) {
            $target->setInstallationSource($installationSource);
            $downloader->update($initial, $target, $targetDir);
        } else {
            $downloader->remove($initial, $targetDir);
            $this->download($target, $targetDir, 'source' === $installationSource);
        }
    }

    /**
     * Removes package from target dir.
     *
     * @param PackageInterface $package   package instance
     * @param string           $targetDir target dir
     */
    public function remove(PackageInterface $package, $targetDir)
    {
        $downloader = $this->getDownloaderForInstalledPackage($package);
        if ($downloader) {
            $downloader->remove($package, $targetDir);
        }
    }
}
