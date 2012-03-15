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
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;

/**
 * Base downloader for files
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class FileDownloader implements DownloaderInterface
{
    protected $io;
    protected $rfs;

    /**
     * Constructor.
     *
     * @param IOInterface  $io  The IO instance
     */
    public function __construct(IOInterface $io, RemoteFilesystem $rfs = null)
    {
        $this->io = $io;
        $this->rfs = $rfs ?: new RemoteFilesystem($io);
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return 'dist';
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

        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new \UnexpectedValueException($path.' exists and is not a directory');
            }
            if (!mkdir($path, 0777, true)) {
                throw new \UnexpectedValueException($path.' does not exist and could not be created');
            }
        }

        $fileName = $this->getFileName($package, $path);

        $this->io->write("  - Package <info>" . $package->getName() . "</info> (<comment>" . $package->getPrettyVersion() . "</comment>)");

        $url = $this->processUrl($url);

        $this->rfs->copy($package->getSourceUrl(), $url, $fileName);
        $this->io->write('');

        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity');
        }

        $checksum = $package->getDistSha1Checksum();
        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification of the file failed (downloaded from '.$url.')');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        $this->remove($initial, $path);
        $this->download($target, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $fs = new Filesystem();
        $fs->removeDirectory($path);
    }

    /**
     * Gets file name for specific package
     *
     * @param  PackageInterface $package   package instance
     * @param  string           $path      download path
     * @return string file name
     */
    protected function getFileName(PackageInterface $package, $path)
    {
        return $path.'/'.pathinfo($package->getDistUrl(), PATHINFO_BASENAME);
    }

    /**
     * Process the download url
     *
     * @param  string           $url       download url
     * @return string url
     *
     * @throws \RuntimeException If any problem with the url
     */
    protected function processUrl($url)
    {
        if (!extension_loaded('openssl') && 0 === strpos($url, 'https:')) {
            throw new \RuntimeException('You must enable the openssl extension to download files via https');
        }

        return $url;
    }
}
