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
 * Base downloader for file packages
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class FileDownloader implements DownloaderInterface
{
    protected $io;

    /**
     * Constructor.
     *
     * @param IOInterface  $io  The IO instance
     */
    public function __construct(IOInterface $io)
    {
        $this->io = $io;
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
        $checksum = $package->getDistSha1Checksum();

        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new \UnexpectedValueException($path.' exists and is not a directory');
            }
            if (!mkdir($path, 0777, true)) {
                throw new \UnexpectedValueException($path.' does not exist and could not be created');
            }
        }

        $fileName = rtrim($path.'/'.md5(time().rand()).'.'.pathinfo($url, PATHINFO_EXTENSION), '.');

        $this->io->write("  - Package <info>" . $package->getName() . "</info> (<comment>" . $package->getPrettyVersion() . "</comment>)");

        if (!extension_loaded('openssl') && (0 === strpos($url, 'https:') || 0 === strpos($url, 'http://github.com'))) {
            // bypass https for github if openssl is disabled
            if (preg_match('{^https?://(github.com/[^/]+/[^/]+/(zip|tar)ball/[^/]+)$}i', $url, $match)) {
                $url = 'http://nodeload.'.$match[1];
            } else {
                throw new \RuntimeException('You must enable the openssl extension to download files via https');
            }
        }

        $rfs = new RemoteFilesystem($this->io);
        $rfs->copy($package->getSourceUrl(), $url, $fileName);
        $this->io->write('');

        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity');
        }

        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification of the archive failed (downloaded from '.$url.')');
        }

        $this->io->write('    Unpacking archive');
        $this->extract($fileName, $path);

        $this->io->write('    Cleaning up');
        unlink($fileName);

        // If we have only a one dir inside it suppose to be a package itself
        $contentDir = glob($path . '/*');
        if (1 === count($contentDir)) {
            $contentDir = $contentDir[0];
            foreach (array_merge(glob($contentDir . '/.*'), glob($contentDir . '/*')) as $file) {
                if (trim(basename($file), '.')) {
                    rename($file, $path . '/' . basename($file));
                }
            }
            rmdir($contentDir);
        }

        $this->io->write('');
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        $fs = new Filesystem();
        $fs->removeDirectory($path);
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
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     *
     * @throws \UnexpectedValueException If can not extract downloaded file to path
     */
    protected abstract function extract($file, $path);
}
