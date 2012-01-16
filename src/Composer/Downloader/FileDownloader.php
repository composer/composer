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
    protected $bytesMax;

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
        // init the progress bar
        $this->bytesMax = 0;

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

        $this->io->writeln("  - Package <info>" . $package->getName() . "</info> (<comment>" . $package->getPrettyVersion() . "</comment>)");

        if (!extension_loaded('openssl') && (0 === strpos($url, 'https:') || 0 === strpos($url, 'http://github.com'))) {
            // bypass https for github if openssl is disabled
            if (preg_match('{^https?://(github.com/[^/]+/[^/]+/(zip|tar)ball/[^/]+)$}i', $url, $match)) {
                $url = 'http://nodeload.'.$match[1];
            } else {
                throw new \RuntimeException('You must enable the openssl extension to download files via https');
            }
        }

        $auth = $this->io->getAuthentification($package->getSourceUrl());

        if (isset($_SERVER['HTTP_PROXY'])) {
            // http(s):// is not supported in proxy
            $proxy = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $_SERVER['HTTP_PROXY']);

            if (0 === strpos($proxy, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }
        }

        $this->io->overwrite("    Downloading: <comment>connection...</comment>", 80);
        $this->copy($url, $fileName, $auth['username'], $auth['password']);
        $this->io->overwriteln("    Downloading", 80);

        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity');
        }

        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification of the archive failed (downloaded from '.$url.')');
        }

        $this->io->writeln('    Unpacking archive');
        $this->extract($fileName, $path);


        $this->io->writeln('    Cleaning up');
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

        $this->io->overwrite('');
        $this->io->writeln('');
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
     * Download notification action.
     *
     * @param integer $sizeTotal  The total size
     * @param integer $sizeLoaded The loaded size
     */
    protected function callbackDownload($sizeTotal, $sizeLoaded)
    {
        if ($sizeTotal > 1024) {
            $progression = 0;

            if ($sizeTotal > 0) {
                $progression = ($sizeLoaded / $sizeTotal * 100);
            }

            $levels = array(0, 5, 10, 15, 20, 25, 30, 35, 40, 35, 50, 55, 60,
                    65, 70, 75, 80, 85, 90, 95, 100);

            $progression = round($progression, 0);

            if (in_array($progression, $levels)) {
                $this->io->overwrite("    Downloading: <comment>$progression%</comment>", 80);
            }
        }
    }

    /**
     * Copy the content in file directory.
     *
     * @param string $url      The file URL
     * @param string $filename The local path
     * @param string $username The username
     * @param string $password The password
     */
    protected function copy($url, $filename, $username = null, $password = null)
    {
        // create directory
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0777, true);
        }

        $fh = fopen($filename, 'c+');

        // curl options
        $defaults = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_BUFFERSIZE => 128000,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => array($this, 'callbackDownload'),
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FILE => $fh,
        );

        // add authorization to curl options
        if (null !== $username && null !== $password) {
            $defaults[CURLOPT_USERPWD] = $username . ':' . $password;
        }

        // init curl
        $ch = curl_init();

        // curl options
        curl_setopt_array($ch, $defaults);

        // run curl
        $curl_result = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $curl_errorCode = curl_errno($ch);
        $curl_error = curl_error($ch);
        $code = $curl_info['http_code'];
        $code = null ? 0 : $code;

        //close streams
        curl_close($ch);
        fclose($fh);

        if (200 !== $code) {
            return false;
        }

        return true;
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
