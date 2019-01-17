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
use React\Promise\PromiseInterface;

/**
 * Downloaders manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class DownloadManager
{
    private $io;
    private $httpDownloader;
    private $preferDist = false;
    private $preferSource = false;
    private $packagePreferences = array();
    private $filesystem;
    private $downloaders = array();

    /**
     * Initializes download manager.
     *
     * @param IOInterface     $io             The Input Output Interface
     * @param bool            $preferSource   prefer downloading from source
     * @param Filesystem|null $filesystem     custom Filesystem object
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
     * Sets fine tuned preference settings for package level source/dist selection.
     *
     * @param  array           $preferences array of preferences by package patterns
     * @return DownloadManager
     */
    public function setPreferences(array $preferences)
    {
        $this->packagePreferences = $preferences;

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
     * @param  string                    $type installation type
     * @throws \InvalidArgumentException if downloader for provided type is not registered
     * @return DownloaderInterface
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
     * @param  PackageInterface          $package package instance
     * @throws \InvalidArgumentException if package has no installation source specified
     * @throws \LogicException           if specific downloader used to load package with
     *                                           wrong type
     * @return DownloaderInterface|null
     */
    public function getDownloaderForPackage(PackageInterface $package)
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
                'Package '.$package.' does not have an installation source set'
            );
        }

        if ($installationSource !== $downloader->getInstallationSource()) {
            throw new \LogicException(sprintf(
                'Downloader "%s" is a %s type downloader and can not be used to download %s for package %s',
                get_class($downloader),
                $downloader->getInstallationSource(),
                $installationSource,
                $package
            ));
        }

        return $downloader;
    }

    public function getDownloaderType(DownloaderInterface $downloader)
    {
        return array_search($downloader, $this->downloaders);
    }

    /**
     * Downloads package into target dir.
     *
     * @param PackageInterface $package      package instance
     * @param string           $targetDir    target dir
     * @param PackageInterface $prevPackage  previous package instance in case of updates
     *
     * @return PromiseInterface
     * @throws \InvalidArgumentException if package have no urls to download from
     * @throws \RuntimeException
     */
    public function download(PackageInterface $package, $targetDir, PackageInterface $prevPackage = null)
    {
        $this->filesystem->ensureDirectoryExists(dirname($targetDir));

        $sources = $this->getAvailableSources($package, $prevPackage);

        $io = $this->io;
        $self = $this;

        $download = function ($retry = false) use (&$sources, $io, $package, $self, $targetDir, &$download) {
            $source = array_shift($sources);
            if ($retry) {
                $io->writeError('    <warning>Now trying to download from ' . $source . '</warning>');
            }
            $package->setInstallationSource($source);

            $downloader = $self->getDownloaderForPackage($package);
            if (!$downloader) {
                return \React\Promise\resolve();
            }

            $handleError = function ($e) use ($sources, $source, $package, $io, $download) {
                if ($e instanceof \RuntimeException) {
                    if (!$sources) {
                        throw $e;
                    }

                    $io->writeError(
                        '    <warning>Failed to download '.
                        $package->getPrettyName().
                        ' from ' . $source . ': '.
                        $e->getMessage().'</warning>'
                    );

                    return $download(true);
                }

                throw $e;
            };

            try {
                $result = $downloader->download($package, $targetDir);
            } catch (\Exception $e) {
                return $handleError($e);
            }
            if (!$result instanceof PromiseInterface) {
                return \React\Promise\resolve($result);
            }

            $res = $result->then(function ($res) {
                return $res;
            }, $handleError);

            return $res;
        };

        return $download();
    }

    /**
     * Installs package into target dir.
     *
     * @param PackageInterface $package      package instance
     * @param string           $targetDir    target dir
     *
     * @throws \InvalidArgumentException if package have no urls to download from
     * @throws \RuntimeException
     */
    public function install(PackageInterface $package, $targetDir)
    {
        $downloader = $this->getDownloaderForPackage($package);
        if ($downloader) {
            $downloader->install($package, $targetDir);
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
        $downloader = $this->getDownloaderForPackage($target);
        $initialDownloader = $this->getDownloaderForPackage($initial);

        // no downloaders present means update from metapackage to metapackage, nothing to do
        if (!$initialDownloader && !$downloader) {
            return;
        }

        // if we have a downloader present before, but not after, the package became a metapackage and its files should be removed
        if (!$downloader) {
            $initialDownloader->remove($initial, $targetDir);
            return;
        }

        $initialType = $this->getDownloaderType($initialDownloader);
        $targetType = $this->getDownloaderType($downloader);
        if ($initialType === $targetType) {
            try {
                $downloader->update($initial, $target, $targetDir);

                return;
            } catch (\RuntimeException $e) {
                if (!$this->io->isInteractive()) {
                    throw $e;
                }
                $this->io->writeError('<error>    Update failed ('.$e->getMessage().')</error>');
                if (!$this->io->askConfirmation('    Would you like to try reinstalling the package instead [<comment>yes</comment>]? ', true)) {
                    throw $e;
                }
            }
        }

        // if downloader type changed, or update failed and user asks for reinstall,
        // we wipe the dir and do a new install instead of updating it
        if ($initialDownloader) {
            $initialDownloader->remove($initial, $targetDir);
        }
        $this->install($target, $targetDir);
    }

    /**
     * Removes package from target dir.
     *
     * @param PackageInterface $package   package instance
     * @param string           $targetDir target dir
     */
    public function remove(PackageInterface $package, $targetDir)
    {
        $downloader = $this->getDownloaderForPackage($package);
        if ($downloader) {
            $downloader->remove($package, $targetDir);
        }
    }

    /**
     * Determines the install preference of a package
     *
     * @param PackageInterface $package package instance
     *
     * @return string
     */
    protected function resolvePackageInstallPreference(PackageInterface $package)
    {
        foreach ($this->packagePreferences as $pattern => $preference) {
            $pattern = '{^'.str_replace('\\*', '.*', preg_quote($pattern)).'$}i';
            if (preg_match($pattern, $package->getName())) {
                if ('dist' === $preference || (!$package->isDev() && 'auto' === $preference)) {
                    return 'dist';
                }

                return 'source';
            }
        }

        return $package->isDev() ? 'source' : 'dist';
    }

    /**
     * @return string[]
     */
    private function getAvailableSources(PackageInterface $package, PackageInterface $prevPackage = null)
    {
        $sourceType = $package->getSourceType();
        $distType = $package->getDistType();

        // add source before dist by default
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

        if (
            $prevPackage
            // if we are updating, we want to keep the same source as the previously installed package (if available in the new one)
            && in_array($prevPackage->getInstallationSource(), $sources, true)
            // unless the previous package was stable dist (by default) and the new package is dev, then we allow the new default to take over
            && !(!$prevPackage->isDev() && $prevPackage->getInstallationSource() === 'dist' && $package->isDev())
        ) {
            $prevSource = $prevPackage->getInstallationSource();
            usort($sources, function ($a, $b) use ($prevSource) {
                return $a === $prevSource ? -1 : 1;
            });

            return $sources;
        }

        // reverse sources in case dist is the preferred source for this package
        if (!$this->preferSource && ($this->preferDist || 'dist' === $this->resolvePackageInstallPreference($package))) {
            $sources = array_reverse($sources);
        }

        return $sources;
    }
}
