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
     * {@inheritDoc}
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function install(PackageInterface $package, $path, $output = true)
    {
        if ($output) {
            $this->io->writeError("  - " . InstallOperation::format($package).": Extracting archive");
        } else {
            $this->io->writeError('Extracting archive', false);
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
        if (!is_dir($path) || realpath($path) !== getcwd()) {
            $this->addCleanupPath($package, $path);
        }

        $this->filesystem->ensureDirectoryExists($temporaryDir);
        $fileName = $this->getFileName($package, $path);

        $filesystem = $this->filesystem;
        $self = $this;

        $cleanup = function () use ($path, $filesystem, $temporaryDir, $package, $self) {
            // remove cache if the file was corrupted
            $self->clearLastCacheWrite($package);

            // clean up
            $filesystem->removeDirectory($temporaryDir);
            if (is_dir($path) && realpath($path) !== getcwd()) {
                $filesystem->removeDirectory($path);
            }
            $self->removeCleanupPath($package, $temporaryDir);
            $self->removeCleanupPath($package, realpath($path));
        };

        $promise = null;
        try {
            $promise = $this->extract($package, $fileName, $temporaryDir);
        } catch (\Exception $e) {
            $cleanup();
            throw $e;
        }

        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve();
        }

        return $promise->then(function () use ($self, $package, $filesystem, $fileName, $temporaryDir, $path) {
            $filesystem->unlink($fileName);

            /**
             * Returns the folder content, excluding .DS_Store
             *
             * @param  string         $dir Directory
             * @return \SplFileInfo[]
             */
            $getFolderContent = function ($dir) {
                $finder = Finder::create()
                    ->ignoreVCS(false)
                    ->ignoreDotFiles(false)
                    ->notName('.DS_Store')
                    ->depth(0)
                    ->in($dir);

                return iterator_to_array($finder);
            };

            $renameAsOne = false;
            if (!file_exists($path) || ($filesystem->isDirEmpty($path) && $filesystem->removeDirectory($path))) {
                $renameAsOne = true;
            }

            $contentDir = $getFolderContent($temporaryDir);
            $singleDirAtTopLevel = 1 === count($contentDir) && is_dir(reset($contentDir));

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
                if ($singleDirAtTopLevel) {
                    $contentDir = $getFolderContent((string) reset($contentDir));
                }

                // move files back out of the temp dir
                foreach ($contentDir as $file) {
                    $file = (string) $file;
                    $filesystem->rename($file, $path . '/' . basename($file));
                }
            }

            $filesystem->removeDirectory($temporaryDir);
            $self->removeCleanupPath($package, $temporaryDir);
            $self->removeCleanupPath($package, $path);
        }, function ($e) use ($cleanup) {
            $cleanup();

            throw $e;
        });
    }

    /**
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     *
     * @throws \UnexpectedValueException If can not extract downloaded file to path
     * @return PromiseInterface|null
     */
    abstract protected function extract(PackageInterface $package, $file, $path);
}
