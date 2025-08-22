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
    protected $archivers = [];

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
     * @return array<string, string>
     * @internal
     */
    public function getPackageFilenameParts(CompletePackageInterface $package): array
    {
        $baseName = $package->getArchiveName();
        if (null === $baseName) {
            $baseName = Preg::replace('#[^a-z0-9-_]#i', '-', $package->getName());
        }

        $parts = [
            'base' => $baseName,
        ];

        $distReference = $package->getDistReference();
        if (null !== $distReference && Preg::isMatch('{^[a-f0-9]{40}$}', $distReference)) {
            $parts['dist_reference'] = $distReference;
            $parts['dist_type'] = $package->getDistType();
        } else {
            $parts['version'] = $package->getPrettyVersion();
            $parts['dist_reference'] = $distReference;
        }

        $sourceReference = $package->getSourceReference();
        if (null !== $sourceReference) {
            $parts['source_reference'] = substr(hash('sha1', $sourceReference), 0, 6);
        }

        $parts = array_filter($parts, static function (?string $part) {
            return $part !== null;
        });
        foreach ($parts as $key => $part) {
            $parts[$key] = str_replace('/', '-', $part);
        }

        return $parts;
    }

    /**
     * @param array<string, string> $parts
     *
     * @internal
     */
    public function getPackageFilenameFromParts(array $parts): string
    {
        return implode('-', $parts);
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
        return $this->getPackageFilenameFromParts($this->getPackageFilenameParts($package));
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
            $sourcePath = sys_get_temp_dir().'/composer_archive'.bin2hex(random_bytes(5));
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

        $supportedFormats = $this->getSupportedFormats();
        $packageNameParts = null === $fileName ?
            $this->getPackageFilenameParts($package)
            : ['base' => $fileName];

        $packageName = $this->getPackageFilenameFromParts($packageNameParts);
        $excludePatterns = $this->buildExcludePatterns($packageNameParts, $supportedFormats);

        // Archive filename
        $filesystem->ensureDirectoryExists($targetDir);
        $target = realpath($targetDir).'/'.$packageName.'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($target));

        if (!$this->overwriteFiles && file_exists($target)) {
            return $target;
        }

        // Create the archive
        $tempTarget = sys_get_temp_dir().'/composer_archive'.bin2hex(random_bytes(5)).'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($tempTarget));

        $archivePath = $usableArchiver->archive(
            $sourcePath,
            $tempTarget,
            $format,
            array_merge($excludePatterns, $package->getArchiveExcludes()),
            $ignoreFilters
        );
        $filesystem->rename($archivePath, $target);

        // cleanup temporary download
        if (!$package instanceof RootPackageInterface) {
            $filesystem->removeDirectory($sourcePath);
        }
        $filesystem->remove($tempTarget);

        return $target;
    }

    /**
     * @param string[] $parts
     * @param string[] $formats
     *
     * @return string[]
     */
    private function buildExcludePatterns(array $parts, array $formats): array
    {
        $base = $parts['base'];
        if (count($parts) > 1) {
            $base .= '-*';
        }

        $patterns = [];
        foreach ($formats as $format) {
            $patterns[] = "$base.$format";
        }

        return $patterns;
    }

    /**
     * @return string[]
     */
    private function getSupportedFormats(): array
    {
        // The problem is that the \Composer\Package\Archiver\ArchiverInterface
        // doesn't provide method to get the supported formats.
        // Supported formats are also hard-coded into the description of the
        // --format option.
        // See \Composer\Command\ArchiveCommand::configure().
        $formats = [];
        foreach ($this->archivers as $archiver) {
            $items = [];
            switch (get_class($archiver)) {
                case ZipArchiver::class:
                    $items = ['zip'];
                    break;

                case PharArchiver::class:
                    $items = ['zip', 'tar', 'tar.gz', 'tar.bz2'];
                    break;
            }

            $formats = array_merge($formats, $items);
        }

        return array_unique($formats);
    }
}
