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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Util\Platform;
use Symfony\Component\Finder\Finder;
use React\Promise\PromiseInterface;
use Composer\DependencyResolver\Operation\InstallOperation;

/**
 * Base downloader for archives
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class ArchiveDownloader extends FileDownloader
{
    /**
     * @var array<string, true>
     */
    protected $cleanupExecuted = [];

    /**
     * @return PromiseInterface
     */
    public function prepare(string $type, PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface
    {
        unset($this->cleanupExecuted[$package->getName()]);

        return parent::prepare($type, $package, $path, $prevPackage);
    }

    /**
     * @return PromiseInterface
     */
    public function cleanup(string $type, PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface
    {
        $this->cleanupExecuted[$package->getName()] = true;

        return parent::cleanup($type, $package, $path, $prevPackage);
    }

    /**
     * @inheritDoc
     *
     * @param bool $output
     *
     * @return PromiseInterface
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function install(PackageInterface $package, string $path, bool $output = true): PromiseInterface
    {
        if ($output) {
            $this->io->writeError("  - " . InstallOperation::format($package) . $this->getInstallOperationAppendix($package, $path));
        }

        $vendorDir = $this->config->get('vendor-dir');

        // clean up the target directory, unless it contains the vendor dir, as the vendor dir contains
        // the archive to be extracted. This is the case when installing with create-project in the current directory
        // but in that case we ensure the directory is empty already in ProjectInstaller so no need to empty it here.
        if (false === strpos($this->filesystem->normalizePath($vendorDir), $this->filesystem->normalizePath($path.DIRECTORY_SEPARATOR))) {
            $this->filesystem->emptyDirectory($path);
        }

        do {
            $temporaryDir = $vendorDir.'/composer/'.substr(md5(uniqid('', true)), 0, 8);
        } while (is_dir($temporaryDir));

        $this->addCleanupPath($package, $temporaryDir);
        // avoid cleaning up $path if installing in "." for eg create-project as we can not
        // delete the directory we are currently in on windows
        if (!is_dir($path) || realpath($path) !== Platform::getCwd()) {
            $this->addCleanupPath($package, $path);
        }

        $this->filesystem->ensureDirectoryExists($temporaryDir);
        $fileName = $this->getFileName($package, $path);

        $filesystem = $this->filesystem;

        $cleanup = function () use ($path, $filesystem, $temporaryDir, $package) {
            // remove cache if the file was corrupted
            $this->clearLastCacheWrite($package);

            // clean up
            $filesystem->removeDirectory($temporaryDir);
            if (is_dir($path) && realpath($path) !== Platform::getCwd()) {
                $filesystem->removeDirectory($path);
            }
            $this->removeCleanupPath($package, $temporaryDir);
            $this->removeCleanupPath($package, realpath($path));
        };

        try {
            $promise = $this->extract($package, $fileName, $temporaryDir);
        } catch (\Exception $e) {
            $cleanup();
            throw $e;
        }

        return $promise->then(function () use ($package, $filesystem, $fileName, $temporaryDir, $path): \React\Promise\PromiseInterface {
            if (file_exists($fileName)) {
                $filesystem->unlink($fileName);
            }

            /**
             * Returns the folder content, excluding .DS_Store
             *
             * @param  string         $dir Directory
             * @return \SplFileInfo[]
             */
            $getFolderContent = function ($dir): array {
                $finder = Finder::create()
                    ->ignoreVCS(false)
                    ->ignoreDotFiles(false)
                    ->notName('.DS_Store')
                    ->depth(0)
                    ->in($dir);

                return iterator_to_array($finder);
            };
            $renameRecursively = null;
            /**
             * Renames (and recursively merges if needed) a folder into another one
             *
             * For custom installers, where packages may share paths, and given Composer 2's parallelism, we need to make sure
             * that the source directory gets merged into the target one if the target exists. Otherwise rename() by default would
             * put the source into the target e.g. src/ => target/src/ (assuming target exists) instead of src/ => target/
             *
             * @param  string $from Directory
             * @param  string $to   Directory
             * @return void
             */
            $renameRecursively = function ($from, $to) use ($filesystem, $getFolderContent, $package, &$renameRecursively) {
                $contentDir = $getFolderContent($from);

                // move files back out of the temp dir
                foreach ($contentDir as $file) {
                    $file = (string) $file;
                    if (is_dir($to . '/' . basename($file))) {
                        if (!is_dir($file)) {
                            throw new \RuntimeException('Installing '.$package.' would lead to overwriting the '.$to.'/'.basename($file).' directory with a file from the package, invalid operation.');
                        }
                        $renameRecursively($file, $to . '/' . basename($file));
                    } else {
                        $filesystem->rename($file, $to . '/' . basename($file));
                    }
                }
            };

            $renameAsOne = false;
            if (!file_exists($path)) {
                $renameAsOne = true;
            } elseif ($filesystem->isDirEmpty($path)) {
                try {
                    if ($filesystem->removeDirectoryPhp($path)) {
                        $renameAsOne = true;
                    }
                } catch (\RuntimeException $e) {
                    // ignore error, and simply do not renameAsOne
                }
            }

            $contentDir = $getFolderContent($temporaryDir);
            $singleDirAtTopLevel = 1 === count($contentDir) && is_dir((string) reset($contentDir));

            if ($renameAsOne) {
                // if the target $path is clear, we can rename the whole package in one go instead of looping over the contents
                if ($singleDirAtTopLevel) {
                    $extractedDir = (string) reset($contentDir);
                } else {
                    $extractedDir = $temporaryDir;
                }
                $filesystem->rename($extractedDir, $path);
            } else {
                // only one dir in the archive, extract its contents out of it
                $from = $temporaryDir;
                if ($singleDirAtTopLevel) {
                    $from = (string) reset($contentDir);
                }

                $renameRecursively($from, $path);
            }

            $promise = $filesystem->removeDirectoryAsync($temporaryDir);

            return $promise->then(function () use ($package, $path, $temporaryDir) {
                $this->removeCleanupPath($package, $temporaryDir);
                $this->removeCleanupPath($package, $path);
            });
        }, function ($e) use ($cleanup) {
            $cleanup();

            throw $e;
        });
    }

    /**
     * @inheritDoc
     */
    protected function getInstallOperationAppendix(PackageInterface $package, string $path): string
    {
        return ': Extracting archive';
    }

    /**
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     *
     * @throws \UnexpectedValueException If can not extract downloaded file to path
     */
    abstract protected function extract(PackageInterface $package, string $file, string $path): PromiseInterface;
}
