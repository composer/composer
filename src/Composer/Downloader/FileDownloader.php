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
    protected $filesystem;

    /**
     * Constructor.
     *
     * @param IOInterface $io The IO instance
     */
    public function __construct(IOInterface $io, RemoteFilesystem $rfs = null, Filesystem $filesystem = null)
    {
        $this->io = $io;
        $this->rfs = $rfs ?: new RemoteFilesystem($io);
        $this->filesystem = $filesystem ?: new Filesystem();
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

        $this->filesystem->ensureDirectoryExists($path);

        $fileName = $this->getFileName($package, $path);

        $this->io->write("  - Installing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

        $processUrl = $this->processUrl($package, $url);

        try {
            $this->rfs->copy($package->getSourceUrl(), $processUrl, $fileName);

            if (!file_exists($fileName)) {
                throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                    .' directory is writable and you have internet connectivity');
            }

            $checksum = $package->getDistSha1Checksum();
            if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
                throw new \UnexpectedValueException('The checksum verification of the file failed (downloaded from '.$url.')');
            }
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
        $this->remove($initial, $path);
        $this->download($target, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->io->write("  - Removing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
        if (!$this->filesystem->removeDirectory($path)) {
            throw new \RuntimeException('Could not completely delete '.$path.', aborting.');
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
        return $path.'/'.pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_BASENAME);
    }

    /**
     * Process the download url
     *
     * @param  PackageInterface $package package the url is coming from
     * @param  string           $url     download url
     * @return string           url
     *
     * @throws \RuntimeException If any problem with the url
     */
    protected function processUrl(PackageInterface $package, $url)
    {
        if (!extension_loaded('openssl') && 0 === strpos($url, 'https:')) {
            throw new \RuntimeException('You must enable the openssl extension to download files via https');
        }

        return $url;
    }
}
