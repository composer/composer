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

use Composer\Config;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class VcsDownloader implements DownloaderInterface, ChangeReportInterface
{
    protected $io;
    protected $config;
    protected $process;
    protected $filesystem;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process = null, Filesystem $fs = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor($io);
        $this->filesystem = $fs ?: new Filesystem;
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return 'source';
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        if (!$package->getSourceReference()) {
            throw new \InvalidArgumentException('Package '.$package->getPrettyName().' is missing reference information');
        }

        $this->io->write("  - Installing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
        $this->filesystem->removeDirectory($path);
        $this->doDownload($package, $path);
        $this->io->write('');
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        if (!$target->getSourceReference()) {
            throw new \InvalidArgumentException('Package '.$target->getPrettyName().' is missing reference information');
        }

        $name = $target->getName();
        if ($initial->getPrettyVersion() == $target->getPrettyVersion()) {
            if ($target->getSourceType() === 'svn') {
                $from = $initial->getSourceReference();
                $to = $target->getSourceReference();
            } else {
                $from = substr($initial->getSourceReference(), 0, 7);
                $to = substr($target->getSourceReference(), 0, 7);
            }
            $name .= ' '.$initial->getPrettyVersion();
        } else {
            $from = VersionParser::formatVersion($initial);
            $to = VersionParser::formatVersion($target);
        }

        $this->io->write("  - Updating <info>" . $name . "</info> (<comment>" . $from . "</comment> => <comment>" . $to . "</comment>)");

        $this->cleanChanges($initial, $path, true);
        try {
            $this->doUpdate($initial, $target, $path);
        } catch (\Exception $e) {
            // in case of failed update, try to reapply the changes before aborting
            $this->reapplyChanges($path);

            throw $e;
        }
        $this->reapplyChanges($path);

        //print the commit logs if in verbose mode
        if ($this->io->isVerbose()) {
            $message = 'Pulling in changes:';
            $logs = $this->getCommitLogs($initial->getSourceReference(), $target->getSourceReference(), $path);

            if (!trim($logs)) {
                $message = 'Rolling back changes:';
                $logs = $this->getCommitLogs($target->getSourceReference(), $initial->getSourceReference(), $path);
            }

            if (trim($logs)) {
                $logs = implode("\n", array_map(function ($line) {
                    return '      ' . $line;
                }, explode("\n", $logs)));

                $this->io->write('    '.$message);
                $this->io->write($logs);
            }
        }

        $this->io->write('');
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->io->write("  - Removing <info>" . $package->getName() . "</info> (<comment>" . $package->getPrettyVersion() . "</comment>)");
        $this->cleanChanges($package, $path, false);
        if (!$this->filesystem->removeDirectory($path)) {
            // retry after a bit on windows since it tends to be touchy with mass removals
            if (!defined('PHP_WINDOWS_VERSION_BUILD') || (usleep(250) && !$this->filesystem->removeDirectory($path))) {
                throw new \RuntimeException('Could not completely delete '.$path.', aborting.');
            }
        }
    }

    /**
     * Download progress information is not available for all VCS downloaders.
     * {@inheritDoc}
     */
    public function setOutputProgress($outputProgress)
    {
        return $this;
    }

    /**
     * Prompt the user to check if changes should be stashed/removed or the operation aborted
     *
     * @param PackageInterface $package
     * @param string           $path
     * @param bool             $update  if true (update) the changes can be stashed and reapplied after an update,
     *                                  if false (remove) the changes should be assumed to be lost if the operation is not aborted
     * @throws \RuntimeException in case the operation must be aborted
     */
    protected function cleanChanges(PackageInterface $package, $path, $update)
    {
        // the default implementation just fails if there are any changes, override in child classes to provide stash-ability
        if (null !== $this->getLocalChanges($package, $path)) {
            throw new \RuntimeException('Source directory ' . $path . ' has uncommitted changes.');
        }
    }

    /**
     * Guarantee that no changes have been made to the local copy
     *
     * @param  string            $path
     * @throws \RuntimeException in case the operation must be aborted or the patch does not apply cleanly
     */
    protected function reapplyChanges($path)
    {
    }

    /**
     * Downloads specific package into specific folder.
     *
     * @param PackageInterface $package package instance
     * @param string           $path    download path
     */
    abstract protected function doDownload(PackageInterface $package, $path);

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param PackageInterface $initial initial package
     * @param PackageInterface $target  updated package
     * @param string           $path    download path
     */
    abstract protected function doUpdate(PackageInterface $initial, PackageInterface $target, $path);

    /**
     * Fetches the commit logs between two commits
     *
     * @param  string $fromReference the source reference
     * @param  string $toReference   the target reference
     * @param  string $path          the package path
     * @return string
     */
    abstract protected function getCommitLogs($fromReference, $toReference, $path);
}
