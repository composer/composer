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
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;
use Composer\Json\JsonFile;

/**
 * @author Matthieu Moquet <matthieu@moquet.net>
 * @author Till Klampaeckel <till@php.net>
 */
class ArchiveManager
{
    protected $downloadManager;

    protected $archivers = array();

    /**
     * @var bool
     */
    protected $overwriteFiles = true;

    /**
     * @param DownloadManager $downloadManager A manager used to download package sources
     */
    public function __construct(DownloadManager $downloadManager)
    {
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
     * Set whether existing archives should be overwritten
     *
     * @param bool $overwriteFiles New setting
     *
     * @return $this
     */
    public function setOverwriteFiles($overwriteFiles)
    {
        $this->overwriteFiles = $overwriteFiles;

        return $this;
    }

    /**
     * Generate a distinct filename for a particular version of a package.
     *
     * @param PackageInterface $package The package to get a name for
     *
     * @return string A filename without an extension
     */
    public function getPackageFilename(PackageInterface $package)
    {
        $nameParts = array(preg_replace('#[^a-z0-9-_]#i', '-', $package->getName()));

        if (preg_match('{^[a-f0-9]{40}$}', $package->getDistReference())) {
            array_push($nameParts, $package->getDistReference(), $package->getDistType());
        } else {
            array_push($nameParts, $package->getPrettyVersion(), $package->getDistReference());
        }

        if ($package->getSourceReference()) {
            $nameParts[] = substr(sha1($package->getSourceReference()), 0, 6);
        }

        $name = implode('-', array_filter($nameParts, function ($p) {
            return !empty($p);
        }));

        return str_replace('/', '-', $name);
    }

    /**
     * Create an archive of the specified package.
     *
     * @param  PackageInterface          $package       The package to archive
     * @param  string                    $format        The format of the archive (zip, tar, ...)
     * @param  string                    $targetDir     The directory where to build the archive
     * @param  string|null               $fileName      The relative file name to use for the archive, or null to generate
     *                                                  the package name. Note that the format will be appended to this name
     * @param  bool                      $ignoreFilters Ignore filters when looking for files in the package
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return string                    The path of the created archive
     */
    public function archive(PackageInterface $package, $format, $targetDir, $fileName = null, $ignoreFilters = false)
    {
        if (empty($format)) {
            throw new \InvalidArgumentException('Format must be specified');
        }

        // Search for the most appropriate archiver
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

        $filesystem = new Filesystem();
        if (null === $fileName) {
            $packageName = $this->getPackageFilename($package);
        } else {
            $packageName = $fileName;
        }

        // Archive filename
        $filesystem->ensureDirectoryExists($targetDir);
        $target = realpath($targetDir).'/'.$packageName.'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($target));

        if (!$this->overwriteFiles && file_exists($target)) {
            return $target;
        }

        if ($package instanceof RootPackageInterface) {
            $sourcePath = realpath('.');
        } else {
            // Directory used to download the sources
            $sourcePath = sys_get_temp_dir().'/composer_archive'.uniqid();
            $filesystem->ensureDirectoryExists($sourcePath);

            try {
                // Download sources
                $this->downloadManager->download($package, $sourcePath);
            } catch (\Exception $e) {
                $filesystem->removeDirectory($sourcePath);
                throw  $e;
            }

            // Check exclude from downloaded composer.json
            if (file_exists($composerJsonPath = $sourcePath.'/composer.json')) {
                $jsonFile = new JsonFile($composerJsonPath);
                $jsonData = $jsonFile->read();
                if (!empty($jsonData['archive']['exclude'])) {
                    $package->setArchiveExcludes($jsonData['archive']['exclude']);
                }
            }
        }

        // Create the archive
        $tempTarget = sys_get_temp_dir().'/composer_archive'.uniqid().'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($tempTarget));

        $archivePath = $usableArchiver->archive($sourcePath, $tempTarget, $format, $package->getArchiveExcludes(), $ignoreFilters);
        $filesystem->rename($archivePath, $target);

        // cleanup temporary download
        if (!$package instanceof RootPackageInterface) {
            $filesystem->removeDirectory($sourcePath);
        }
        $filesystem->remove($tempTarget);

        return $target;
    }
}
