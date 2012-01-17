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

/**
 * Base downloader for file packages
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class FileDownloader implements DownloaderInterface
{
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

        $temporaryName = md5($package->getName() . $package->getVersion());
        $fileName = rtrim($path.'/'.$temporaryName.'.'.pathinfo($url, PATHINFO_EXTENSION), '.');

        if (!file_exists($fileName)) {
            $this->fetch($url, $fileName);
        }

        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification of the archive failed (downloaded from '.$url.')');
        }
    }

    /**
     * Unpacks previously downloaded packages
     *
     * @param PackageInterface $package package instance
     * @param string           $path    target path
     */
    public function unpack(PackageInterface $package, $path)
    {
        $url = $package->getDistUrl();
        $temporaryName = md5($package->getName() . $package->getVersion());
        $fileName = rtrim($path.'/'.$temporaryName.'.'.pathinfo($url, PATHINFO_EXTENSION), '.');

        echo 'Unpacking archive for ', $package->getName(), PHP_EOL;
        $this->extract($fileName, $path);

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
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        $fs = new Util\Filesystem();
        $fs->removeDirectory($path);
        $this->download($target, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $fs = new Util\Filesystem();
        $fs->removeDirectory($path);
    }

    /**
     * Fetches specific package from specific url.
     *
     * @param string $url      url to download from
     * @param string $fileName target filename
     *
     * @throws \RuntimeException|\UnexpectedValueException
     */
    protected function fetch($url, $fileName)
    {
        echo 'Downloading '.$url.' to '.$fileName.PHP_EOL;

        if (!extension_loaded('openssl') && (0 === strpos($url, 'https:') || 0 === strpos($url, 'http://github.com'))) {
            // bypass https for github if openssl is disabled
            if (preg_match('{^https?://(github.com/[^/]+/[^/]+/(zip|tar)ball/[^/]+)$}i', $url, $match)) {
                $url = 'http://nodeload.'.$match[1];
            } else {
                throw new \RuntimeException('You must enable the openssl extension to download files via https');
            }
        }

        // Handle system proxy
        if (isset($_SERVER['HTTP_PROXY'])) {
            // http(s):// is not supported in proxy
            $proxy = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $_SERVER['HTTP_PROXY']);

            if (0 === strpos($proxy, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $ctx = stream_context_create(array(
                'http' => array(
                    'proxy'           => $proxy,
                    'request_fulluri' => true,
                ),
            ));

            copy($url, $fileName, $ctx);
        } else {
            copy($url, $fileName);
        }

        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity');
        }
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
