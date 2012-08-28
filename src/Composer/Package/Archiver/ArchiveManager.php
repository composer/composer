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

namespace Composer\Package\Archiver;

use Composer\Downloader\DownloadManager;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * @author Matthieu Moquet <matthieu@moquet.net>
 * @author Till Klampaeckel <till@php.net>
 */
class ArchiveManager
{
    protected $buildDir;

    protected $downloadManager;

    protected $archivers = array();

    /**
     * @param string          $buildDir        The directory used to build the archive
     * @param DownloadManager $downloadManager A manager used to download package sources
     */
    public function __construct($buildDir, DownloadManager $downloadManager)
    {
        $this->buildDir = $buildDir;
        $this->downloadManager = $downloadManager;
    }

    /**
     * @param ArchiverInterface $archiver
     */
    public function addArchiver(ArchiverInterface $archiver)
    {
        $this->archivers[] = $archiver;
    }

    /**
     * Create an archive of the specified package.
     *
     * @param PackageInterface $package The package to archive
     * @param string           $format  The format of the archive (zip, tar, ...)
     *
     * @return string The path of the created archive
     */
    public function archive(PackageInterface $package, $format)
    {
        if (empty($format)) {
            throw new \InvalidArgumentException('Format must be specified');
        }

        $usableArchiver = null;
        foreach ($this->archivers as $archiver) {
            if ($archiver->supports($format, $package->getSourceType())) {
                $usableArchiver = $archiver;
                break;
            }
        }

        // Checks the format/source type are supported before downloading the package
        if (null === $usableArchiver) {
            throw new \RuntimeException(sprintf('No archiver found to support %s format', $format));
        }

        // Directory used to download the sources
        $filesystem = new Filesystem();
        $packageName = $package->getUniqueName();
        $sources = sys_get_temp_dir().'/'.$packageName;
        $filesystem->ensureDirectoryExists($sources);

        // Archive filename
        $target = $this->buildDir.'/'.$packageName.'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($this->buildDir.$target));

        // Download sources
        $this->downloadManager->download($package, $sources, true);

        // Create the archive
        $sourceRef = $package->getSourceReference();
        $usableArchiver->archive($sources, $target, $format, $sourceRef);
    }
}
