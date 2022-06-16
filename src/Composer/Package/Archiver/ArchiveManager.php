<?php declare(strict_types=1);

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
use Composer\Package\RootPackageInterface;
use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Composer\Util\Loop;
use Composer\Util\SyncHelper;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackageInterface;

/**
 * @author Matthieu Moquet <matthieu@moquet.net>
 * @author Till Klampaeckel <till@php.net>
 */
class ArchiveManager
{
    /** @var DownloadManager */
    protected $downloadManager;
    /** @var Loop */
    protected $loop;

    /**
     * @var ArchiverInterface[]
     */
    protected $archivers = array();

    /**
     * @var bool
     */
    protected $overwriteFiles = true;

    /**
     * @param DownloadManager $downloadManager A manager used to download package sources
     */
    public function __construct(DownloadManager $downloadManager, Loop $loop)
    {
        $this->downloadManager = $downloadManager;
        $this->loop = $loop;
    }

    /**
     * @param ArchiverInterface $archiver
     *
     * @return void
     */
    public function addArchiver(ArchiverInterface $archiver): void
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
    public function setOverwriteFiles(bool $overwriteFiles): self
    {
        $this->overwriteFiles = $overwriteFiles;

        return $this;
    }

    /**
     * Generate a distinct filename for a particular version of a package.
     *
     * @param CompletePackageInterface $package The package to get a name for
     *
     * @return string A filename without an extension
     */
    public function getPackageFilename(CompletePackageInterface $package): string
    {
        if ($package->getArchiveName()) {
            $baseName = $package->getArchiveName();
        } else {
            $baseName = Preg::replace('#[^a-z0-9-_]#i', '-', $package->getName());
        }
        $nameParts = array($baseName);

        if (null !== $package->getDistReference() && Preg::isMatch('{^[a-f0-9]{40}$}', $package->getDistReference())) {
            array_push($nameParts, $package->getDistReference(), $package->getDistType());
        } else {
            array_push($nameParts, $package->getPrettyVersion(), $package->getDistReference());
        }

        if ($package->getSourceReference()) {
            $nameParts[] = substr(sha1($package->getSourceReference()), 0, 6);
        }

        $name = implode('-', array_filter($nameParts, fn ($p): bool => !empty($p)));

        return str_replace('/', '-', $name);
    }

    /**
     * Create an archive of the specified package.
     *
     * @param  CompletePackageInterface  $package       The package to archive
     * @param  string                    $format        The format of the archive (zip, tar, ...)
     * @param  string                    $targetDir     The directory where to build the archive
     * @param  string|null               $fileName      The relative file name to use for the archive, or null to generate
     *                                                  the package name. Note that the format will be appended to this name
     * @param  bool                      $ignoreFilters Ignore filters when looking for files in the package
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @return string                    The path of the created archive
     */
    public function archive(CompletePackageInterface $package, string $format, string $targetDir, ?string $fileName = null, bool $ignoreFilters = false): string
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

        if ($package instanceof RootPackageInterface) {
            $sourcePath = realpath('.');
        } else {
            // Directory used to download the sources
            $sourcePath = sys_get_temp_dir().'/composer_archive'.uniqid();
            $filesystem->ensureDirectoryExists($sourcePath);

            try {
                // Download sources
                $promise = $this->downloadManager->download($package, $sourcePath);
                SyncHelper::await($this->loop, $promise);
                $promise = $this->downloadManager->install($package, $sourcePath);
                SyncHelper::await($this->loop, $promise);
            } catch (\Exception $e) {
                $filesystem->removeDirectory($sourcePath);
                throw  $e;
            }

            // Check exclude from downloaded composer.json
            if (file_exists($composerJsonPath = $sourcePath.'/composer.json')) {
                $jsonFile = new JsonFile($composerJsonPath);
                $jsonData = $jsonFile->read();
                if (!empty($jsonData['archive']['name'])) {
                    $package->setArchiveName($jsonData['archive']['name']);
                }
                if (!empty($jsonData['archive']['exclude'])) {
                    $package->setArchiveExcludes($jsonData['archive']['exclude']);
                }
            }
        }

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
