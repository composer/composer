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

use Composer\Package\Archiver\ArchivableFilesFinder;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem as ComposerFilesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Download a package from a local path.
 *
 * @author Samuel Roze <samuel.roze@gmail.com>
 * @author Johann Reinke <johann.reinke@gmail.com>
 */
class PathDownloader extends FileDownloader implements VcsCapableDownloaderInterface
{
    const STRATEGY_SYMLINK = 10;
    const STRATEGY_MIRROR = 20;

    /**
     * {@inheritdoc}
     */
    public function download(PackageInterface $package, $path, $output = true)
    {
        $url = $package->getDistUrl();
        $realUrl = realpath($url);
        if (false === $realUrl || !file_exists($realUrl) || !is_dir($realUrl)) {
            throw new \RuntimeException(sprintf(
                'Source path "%s" is not found for package %s',
                $url,
                $package->getName()
            ));
        }

        if (realpath($path) === $realUrl) {
            if ($output) {
                $this->io->writeError(sprintf(
                    '  - Installing <info>%s</info> (<comment>%s</comment>): Source already present',
                    $package->getName(),
                    $package->getFullPrettyVersion()
                ));
            }

            return;
        }

        if (strpos(realpath($path) . DIRECTORY_SEPARATOR, $realUrl . DIRECTORY_SEPARATOR) === 0) {
            // IMPORTANT NOTICE: If you wish to change this, don't. You are wasting your time and ours.
            //
            // Please see https://github.com/composer/composer/pull/5974 and https://github.com/composer/composer/pull/6174
            // for previous attempts that were shut down because they did not work well enough or introduced too many risks.
            throw new \RuntimeException(sprintf(
                'Package %s cannot install to "%s" inside its source at "%s"',
                $package->getName(),
                realpath($path),
                $realUrl
            ));
        }

        // Get the transport options with default values
        $transportOptions = $package->getTransportOptions() + array('symlink' => null);

        // When symlink transport option is null, both symlink and mirror are allowed
        $currentStrategy = self::STRATEGY_SYMLINK;
        $allowedStrategies = array(self::STRATEGY_SYMLINK, self::STRATEGY_MIRROR);

        $mirrorPathRepos = getenv('COMPOSER_MIRROR_PATH_REPOS');
        if ($mirrorPathRepos) {
            $currentStrategy = self::STRATEGY_MIRROR;
        }

        if (true === $transportOptions['symlink']) {
            $currentStrategy = self::STRATEGY_SYMLINK;
            $allowedStrategies = array(self::STRATEGY_SYMLINK);
        } elseif (false === $transportOptions['symlink']) {
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = array(self::STRATEGY_MIRROR);
        }

        // Check we can use junctions safely if we are on Windows
        if (Platform::isWindows() && self::STRATEGY_SYMLINK === $currentStrategy && !$this->safeJunctions()) {
            $currentStrategy = self::STRATEGY_MIRROR;
            $allowedStrategies = array(self::STRATEGY_MIRROR);
        }

        $fileSystem = new Filesystem();
        $this->filesystem->removeDirectory($path);

        if ($output) {
            $this->io->writeError(sprintf(
                '  - Installing <info>%s</info> (<comment>%s</comment>): ',
                $package->getName(),
                $package->getFullPrettyVersion()
            ), false);
        }

        $isFallback = false;
        if (self::STRATEGY_SYMLINK == $currentStrategy) {
            try {
                if (Platform::isWindows()) {
                    // Implement symlinks as NTFS junctions on Windows
                    $this->io->writeError(sprintf('Junctioning from %s', $url), false);
                    $this->filesystem->junction($realUrl, $path);
                } else {
                    $absolutePath = $path;
                    if (!$this->filesystem->isAbsolutePath($absolutePath)) {
                        $absolutePath = getcwd() . DIRECTORY_SEPARATOR . $path;
                    }
                    $shortestPath = $this->filesystem->findShortestPath($absolutePath, $realUrl);
                    $path = rtrim($path, "/");
                    $this->io->writeError(sprintf('Symlinking from %s', $url), false);
                    $fileSystem->symlink($shortestPath, $path);
                }
            } catch (IOException $e) {
                if (in_array(self::STRATEGY_MIRROR, $allowedStrategies)) {
                    $this->io->writeError('');
                    $this->io->writeError('    <error>Symlink failed, fallback to use mirroring!</error>');
                    $currentStrategy = self::STRATEGY_MIRROR;
                    $isFallback = true;
                } else {
                    throw new \RuntimeException(sprintf('Symlink from "%s" to "%s" failed!', $realUrl, $path));
                }
            }
        }

        // Fallback if symlink failed or if symlink is not allowed for the package
        if (self::STRATEGY_MIRROR == $currentStrategy) {
            $fs = new ComposerFilesystem();
            $realUrl = $fs->normalizePath($realUrl);

            $this->io->writeError(sprintf('%sMirroring from %s', $isFallback ? '    ' : '', $url), false);
            $iterator = new ArchivableFilesFinder($realUrl, array());
            $fileSystem->mirror($realUrl, $path, $iterator);
        }

        $this->io->writeError('');
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path, $output = true)
    {
        $realUrl = realpath($package->getDistUrl());

        if (realpath($path) === $realUrl) {
            if ($output) {
                $this->io->writeError("  - Removing <info>" . $package->getName() . "</info> (<comment>" . $package->getFullPrettyVersion() . "</comment>), source is still present in $path");
            }

            return;
        }

        /**
         * For junctions don't blindly rely on Filesystem::removeDirectory as it may be overzealous. If a process
         * inadvertently locks the file the removal will fail, but it would fall back to recursive delete which
         * is disastrous within a junction. So in that case we have no other real choice but to fail hard.
         */
        if (Platform::isWindows() && $this->filesystem->isJunction($path)) {
            if ($output) {
                $this->io->writeError("  - Removing junction for <info>" . $package->getName() . "</info> (<comment>" . $package->getFullPrettyVersion() . "</comment>)");
            }
            if (!$this->filesystem->removeJunction($path)) {
                $this->io->writeError("    <warning>Could not remove junction at " . $path . " - is another process locking it?</warning>");
                throw new \RuntimeException('Could not reliably remove junction for package ' . $package->getName());
            }
        } else {
            parent::remove($package, $path, $output);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getVcsReference(PackageInterface $package, $path)
    {
        $parser = new VersionParser;
        $guesser = new VersionGuesser($this->config, new ProcessExecutor($this->io), $parser);
        $dumper = new ArrayDumper;

        $packageConfig = $dumper->dump($package);
        if ($packageVersion = $guesser->guessVersion($packageConfig, $path)) {
            return $packageVersion['commit'];
        }
    }

    /**
     * Returns true if junctions can be created and safely used on Windows
     *
     * A PHP bug makes junction detection fragile, leading to possible data loss
     * when removing a package. See https://bugs.php.net/bug.php?id=77552
     *
     * For safety we require a minimum version of Windows 7, so we can call the
     * system rmdir which will preserve target content if given a junction.
     *
     * The PHP bug was fixed in 7.2.16 and 7.3.3 (requires at least Windows 7).
     *
     * @return bool
     */
    private function safeJunctions()
    {
        // We need to call mklink, and rmdir on Windows 7 (version 6.1)
        return function_exists('proc_open') &&
            (PHP_WINDOWS_VERSION_MAJOR > 6 ||
            (PHP_WINDOWS_VERSION_MAJOR === 6 && PHP_WINDOWS_VERSION_MINOR >= 1));
    }
}
