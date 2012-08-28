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
    public function __construct($buildDir, DownloadManager $downloadManager = null)
    {
        $this->buildDir = $buildDir;

        if (null !== $downloadManager) {
            $this->downloadManager = $downloadManager;
        } else {
            $factory = new Factory();
            $this->downloadManager = $factory->createDownloadManager(new NullIO());
        }
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
        $sourceType = $package->getSourceType();

        foreach ($this->archivers as $archiver) {
            if ($archiver->supports($format, $package->getSourceType())) {
                $usableArchiver = $archiver;
            }
        }

        // Checks the format/source type are supported before downloading the package
        if (null === $usableArchiver) {
            throw new \RuntimeException(sprintf('No archiver found to support %s format', $format));
        }

        $filesystem = new Filesystem();
        $packageName = str_replace('/', DIRECTORY_SEPARATOR, $package->getUniqueName());

        // Directory used to download the sources
        $sources = sys_get_temp_dir().DIRECTORY_SEPARATOR.$packageName;
        $filesystem->ensureDirectoryExists($sources);

        // Archive filename
        $target = $this->buildDir.DIRECTORY_SEPARATOR.$packageName.'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($this->buildDir.$target));

        // Download sources
        $this->downloadManager->download($package, $sources, true);

        $sourceRef = $package->getSourceReference();
        $usableArchiver->archive($sources, $target, $format, $sourceRef);
    }
}
