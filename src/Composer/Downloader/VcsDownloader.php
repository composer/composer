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
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class VcsDownloader implements DownloaderInterface
{
    protected $io;
    protected $process;
    protected $filesystem;

    public function __construct(IOInterface $io, ProcessExecutor $process = null, Filesystem $fs = null)
    {
        $this->io = $io;
        $this->process = $process ?: new ProcessExecutor;
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

        $this->io->write("  - Package <info>" . $package->getName() . "</info> (<comment>" . $package->getPrettyVersion() . "</comment>)");
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

        $this->io->write("  - Package <info>" . $target->getName() . "</info> (<comment>" . $target->getPrettyVersion() . "</comment>)");
        $this->enforceCleanDirectory($path);
        $this->doUpdate($initial, $target, $path);
        $this->io->write('');
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->enforceCleanDirectory($path);
        if (!$this->filesystem->removeDirectory($path)) {
            throw new \RuntimeException('Could not completely delete '.$path.', aborting.');
        }
    }

    /**
     * Downloads specific package into specific folder.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $path       download path
     */
    abstract protected function doDownload(PackageInterface $package, $path);

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param   PackageInterface    $initial    initial package
     * @param   PackageInterface    $target     updated package
     * @param   string              $path       download path
     */
    abstract protected function doUpdate(PackageInterface $initial, PackageInterface $target, $path);

    /**
     * Checks that no changes have been made to the local copy
     *
     * @throws \RuntimeException if the directory is not clean
     */
    abstract protected function enforceCleanDirectory($path);
}
