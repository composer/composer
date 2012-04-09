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

namespace Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Downloader\DownloadManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Package\PackageInterface;

/**
 * PHP Extension Installer.
 *
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class ExtensionInstaller extends LibraryInstaller
{
    protected $extDir;

    /**
     * {@inheritDoc}
     */
    public function __construct($vendorDir, $binDir, $extDir, DownloadManager $dm, WritableRepositoryInterface $repository, IOInterface $io, $type = 'extension')
    {
        parent::__construct($vendorDir, $binDir, $dm, $repository, $io, $type);

        $this->extDir = $extDir;
    }

    /**
     * {@inheritDoc}
     */
    public function install(PackageInterface $package)
    {
        parent::install($package);

        $this->compileExtension($package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target)
    {
        parent::update($initial, $target);

        $this->cleanExtension($package);
        $this->compileExtension($package);
    }

    protected function initializeDirs()
    {
        parent::initializeDirs();

        $this->filesystem->ensureDirectoryExists($this->extDir);
        $this->extDir = realpath($this->extDir);
    }

    private function compileExtension(PackageInterface $package)
    {
        $path = $this->getInstallPath($package);
        $command = sprintf('cd %s; phpize && ./configure && make', escapeshellarg($path));
        passthru($command);

        $modulesDir = $this->getInstallPath($package).'/modules';
        foreach (new \FilesystemIterator($modulesDir) as $file) {
            copy($file, $this->extDir.'/'.$file->getBasename());
        }
    }

    private function cleanExtension(PackageInterface $package)
    {
        $path = $this->getInstallPath($package);
        $command = sprintf('cd %s; make clean', escapeshellarg($path));
        passthru($command);
    }
}
