<?php

namespace Composer\Downloader;

use Composer\Cache;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;

class LocalDownloader implements DownloaderInterface
{
    protected $io;
    protected $config;
    protected $rfs;
    protected $filesystem;
    protected $cache;
    protected $outputProgress = true;
    protected $process;

    public function __construct(
        IOInterface $io,
        Config $config,
        EventDispatcher $eventDispatcher = null,
        Cache $cache = null,
        RemoteFilesystem $rfs = null,
        Filesystem $filesystem = null
    ) {
        $this->io = $io;
        $this->config = $config;
        $this->eventDispatcher = $eventDispatcher;
        $this->rfs = $rfs ?: new RemoteFilesystem($io, $config);
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->cache = $cache;
        $this->process = new ProcessExecutor($io);
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

        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        if (0 ==! ($command = $this->process->execute("cp -r ".ProcessExecutor::escape($source.'/')." ".ProcessExecutor::escape($target)))) {
            $this->io->write('Failed to execute command.'.$command. "\n\n" . $this->process->getErrorOutput());
        }

        $this->io->write('<info>Update Package</info> '.$package->getName());
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
        if (0 ==! ($command = $this->process->execute("rm -rf ".ProcessExecutor::escape($target)))) {
            $this->io->write('Failed to execute command.'.$command. "\n\n" . $this->process->getErrorOutput());
        }
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


}
 