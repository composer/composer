<?php

namespace Composer\Downloader;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Filesystem\Filesystem;

class LocalDownloader implements DownloaderInterface
{
    protected $io;

    protected $process;

    protected $downloadManager;

    protected $preferSymlink = false;

    protected $filesystem;

    public function __construct(
        IOInterface $io
    ) {
        $this->io = $io;
        $this->process = new ProcessExecutor($io);
        $this->filesystem = new Filesystem();
    }

    /**
     * Returns installation source (either source or dist).
     *
     * @return string "source" or "dist"
     */
    public function getInstallationSource()
    {
        return 'dist';
    }

    /**
     * Downloads specific package into specific folder.
     *
     * @param PackageInterface $package package instance
     * @param string $target download path
     */
    public function download(PackageInterface $package, $target)
    {
        $source = dirname($package->getDistUrl());

        if (!$this->filesystem->exists($target)) {
            $this->filesystem->mkdir($target);
        }

        if ($this->preferSymlink) {
            $this->filesystem->remove($target);
            $this->filesystem->symlink($source, $target, true);
        } else {
            $this->filesystem->mirror($source, $target);
        }

        $this->io->write('<info>Update Package</info> ' . $package->getName());
    }

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param PackageInterface $initial initial package
     * @param PackageInterface $target updated package
     * @param string $path download path
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        $this->remove($initial, $path);
        $this->download($initial, $path);
    }

    /**
     * Removes specific package from specific folder.
     *
     * @param PackageInterface $package package instance
     * @param string $path download path
     */
    public function remove(PackageInterface $package, $target)
    {
        $this->filesystem->remove($target);
    }

    /**
     * Sets whether to output download progress information or not
     *
     * @param  bool $outputProgress
     * @return DownloaderInterface
     */
    public function setOutputProgress($outputProgress)
    {
        // TODO: Implement setOutputProgress() method.
    }

    /**
     * Some downloaders supports setting symlink instead of downloading the resources.
     *
     * @param  bool $preferSymlink
     * @return DownloaderInterface
     */
    public function setPreferSymlink($preferSymlink)
    {
        $this->preferSymlink = $preferSymlink;
    }


}
 