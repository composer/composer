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
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Util\Filesystem;
use Composer\Util\GitHub;
use Composer\Util\RemoteFilesystem;

/**
 * Base downloader for directories
 *
 * @author Daniel Fahlke aka Flyingmana <danielm@digitalmanufaktur.com>
 */
class LocalDirectoryDownloader implements DownloaderInterface
{

    /**
     * Constructor.
     *
     * @param IOInterface      $io         The IO instance
     * @param Config           $config     The config
     * @param RemoteFilesystem $rfs        The remote filesystem
     * @param Filesystem       $filesystem The filesystem
     */
    public function __construct(IOInterface $io, Config $config, Filesystem $filesystem = null)
    {
        $this->io = $io;
        $this->config = $config;
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

        $dirName = $path;

        var_dump($path);
        $this->io->write("  - Installing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");


        try {
            try {
                $this->filesystem->copyDirectory($url,$dirName);// ->copy(parse_url($processUrl, PHP_URL_HOST), $processUrl, $fileName);
            } catch (TransportException $e) {
                throw $e;
            }

            if (!is_dir($dirName)) {
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
}
