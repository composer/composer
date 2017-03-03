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

use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
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
    const STRATEGY_MIRROR  = 20;

    /**
     * {@inheritdoc}
     */
    public function download(PackageInterface $package, $path, $output = true)
    {
        // Make sure that the source path for the package exists.
        $url = $package->getDistUrl();
        $realUrl = realpath($url);
        if (false === $realUrl || !file_exists($realUrl) || !is_dir($realUrl)) {
            throw new \RuntimeException(sprintf(
                'Source path "%s" is not found for package %s', $url, $package->getName()
            ));
        }

        // Prevent target from being same as source, as this has fatal consequences
        // when deleting packages.
        if (strpos(realpath($path) . DIRECTORY_SEPARATOR, $realUrl . DIRECTORY_SEPARATOR) === 0) {
            throw new \RuntimeException(sprintf(
                'Package %s cannot install to "%s" inside its source at "%s"',
                $package->getName(), realpath($path), $realUrl
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

        $fileSystem = new Filesystem();
        $this->filesystem->removeDirectory($path);

        if ($output) {
            $this->io->writeError(sprintf(
                '  - Installing <info>%s</info> (<comment>%s</comment>)',
                $package->getName(),
                $package->getFullPrettyVersion()
            ), false);
        }

        $isFallback = false;
        $linked = false;

        if (self::STRATEGY_SYMLINK == $currentStrategy) {

            $absolutePath = $path;
            if (!$this->filesystem->isAbsolutePath($absolutePath)) {
                $absolutePath = getcwd() . DIRECTORY_SEPARATOR . $path;
            }
            $shortestPath = $this->filesystem->findShortestPath($absolutePath, $realUrl);
            $path = rtrim($path, "/");

            try {
                // Creating symlinks on windows through PHP's symlink function
                // is totally broken with relative paths.
                // @see https://github.com/php/php-src/pull/1243
                // @see https://bugs.php.net/bug.php?id=69473
                $fileSystem->symlink($shortestPath, $path);
                $this->io->writeError(sprintf(' Symlinked from %s', $url), false);
                $linked = true;
            } catch (IOException $e) {
                $this->io->writeError($e->getMessage(), false);
            }

            if (!$linked && Platform::isWindows()) {
                // Use command linke symlinking as a fallback.
                try {
                    $exitCode = 0;
                    $output = array();
                    // Without this cleanup for backslashes symlink will
                    // be created, but completely broken.
                    $shortestPath = str_replace('/', '\\', $shortestPath);
                    exec(sprintf('mklink /d %s %s', escapeshellarg($absolutePath), escapeshellarg($shortestPath)), $ouptput, $exitCode);
                    $linked = $exitCode == 0;
                }
                catch (\Exception $e) {
                    $this->io->writeError($e->getMessage(), false);
                }
            }

            if (!$linked && Platform::isWindows()) {
                try {
                    // Implement symlinks as NTFS junctions on Windows
                    // this 'should' be considered an error
                    // because junctions are not portable. Yet, good
                    // enough for dev environments.
                    $this->filesystem->junction($realUrl, $path);
                    $this->io->writeError(sprintf(' Junctioned from %s', $url), false);
                    $linked = true;
                }
                catch (IOException $e) {
                    $this->io->writeError($e->getMessage(), false);
                }
            }

            if (!$linked && !in_array(self::STRATEGY_MIRROR, $allowedStrategies)) {
                throw new \RuntimeException(sprintf('Symlink from "%s" to "%s" failed!', $realUrl, $path));
            }

            if (!$linked) {
                $this->io->writeError('');
                $this->io->writeError('    <error>Symlink failed, fallback to use mirroring!</error>');
                $currentStrategy = self::STRATEGY_MIRROR;
                $isFallback = true;
            }
        }

        // Fallback if symlink failed or if symlink is not allowed for the package
        if (self::STRATEGY_MIRROR == $currentStrategy) {
            $fileSystem->mirror($realUrl, $path);
            $this->io->writeError(sprintf('%s Mirrored from %s', $isFallback ? '   ' : '', $url), false);
        }

        $this->io->writeError('');
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path, $output = true)
    {
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
                $this->io->writeError("    <warn>Could not remove junction at " . $path . " - is another process locking it?</warn>");
                throw new \RuntimeException('Could not reliably remove junction for package ' . $package->getName());
            }
        } else {
            parent::remove($package, $path);
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
}
