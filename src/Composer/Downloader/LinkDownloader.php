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

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Util\Filesystem;

/**
 * Base downloader for files
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Filip Procházka <filip.prochazka@kdyby.org>
 */
class LinkDownloader implements DownloaderInterface
{
    protected $io;
    protected $filesystem;

    /**
     * Constructor.
     *
     * @param IOInterface $io The IO instance
     * @param Filesystem $filesystem
     */
    public function __construct(IOInterface $io, Filesystem $filesystem = null)
    {
        $this->io = $io;
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return 'link';
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        $url = $package->getDistUrl();
        if (!$url) {
            throw new \InvalidArgumentException('The given package is missing url information');
        }

        // ensure vendor directory exists
        $this->filesystem->ensureDirectoryExists(dirname($path));

        $this->io->write("  - Linking <info>" . $package->getName() . "</info>");

        try {
            $fileName = $this->getFileName($package, $path);
            $this->filesystem->link($url, $fileName);

        } catch (\Exception $e) {
            // clean up
            $this->filesystem->removeDirectory($path);
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        // It's a symlink! Do not change it, just
        try {
            $this->filesystem->ensureHealthyLink($path);

        } catch (\Exception $e) {
            $this->filesystem->removeDirectory($path);
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->io->write("  - Removing <info>" . $package->getName() . "</info>");
        if (!$this->filesystem->removeDirectory($path)) {
            throw new \RuntimeException('Could not unlink ' . $path . ', aborting.');
        }
    }

    /**
     * Gets file name for specific package
     *
     * @param  PackageInterface $package package instance
     * @param  string           $path    download path
     * @return string           file name
     */
    protected function getFileName(PackageInterface $package, $path)
    {
        return $path . '/' . pathinfo($package->getDistUrl(), PATHINFO_BASENAME);
    }

}
