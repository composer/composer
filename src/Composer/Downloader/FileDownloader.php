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
use Composer\Cache;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\Comparer\Comparer;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use Composer\Util\Url as UrlUtil;

/**
 * Base downloader for files
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class FileDownloader implements DownloaderInterface, ChangeReportInterface
{
    protected $io;
    protected $config;
    protected $rfs;
    protected $filesystem;
    protected $cache;
    protected $outputProgress = true;
    private $lastCacheWrites = array();
    private $eventDispatcher;

    /**
     * Constructor.
     *
     * @param IOInterface      $io              The IO instance
     * @param Config           $config          The config
     * @param EventDispatcher  $eventDispatcher The event dispatcher
     * @param Cache            $cache           Optional cache instance
     * @param RemoteFilesystem $rfs             The remote filesystem
     * @param Filesystem       $filesystem      The filesystem
     */
    public function __construct(IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null, Cache $cache = null, RemoteFilesystem $rfs = null, Filesystem $filesystem = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->eventDispatcher = $eventDispatcher;
        $this->rfs = $rfs ?: Factory::createRemoteFilesystem($this->io, $config);
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->cache = $cache;

        if ($this->cache && $this->cache->gcIsNecessary()) {
            $this->cache->gc($config->get('cache-files-ttl'), $config->get('cache-files-maxsize'));
        }
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
    public function download(PackageInterface $package, $path, $output = true)
    {
        if (!$package->getDistUrl()) {
            throw new \InvalidArgumentException('The given package is missing url information');
        }

        if ($output) {
            $this->io->writeError("  - Installing <info>" . $package->getName() . "</info> (<comment>" . $package->getFullPrettyVersion() . "</comment>): ", false);
        }

        $urls = $package->getDistUrls();
        while ($url = array_shift($urls)) {
            try {
                $fileName = $this->doDownload($package, $path, $url);
                break;
            } catch (\Exception $e) {
                if ($this->io->isDebug()) {
                    $this->io->writeError('');
                    $this->io->writeError('Failed: ['.get_class($e).'] '.$e->getCode().': '.$e->getMessage());
                } elseif (count($urls)) {
                    $this->io->writeError('');
                    $this->io->writeError(' Failed, trying the next URL ('.$e->getCode().': '.$e->getMessage().')', false);
                }

                if (!count($urls)) {
                    throw $e;
                }
            }
        }

        if ($output) {
            $this->io->writeError('');
        }

        return $fileName;
    }

    protected function doDownload(PackageInterface $package, $path, $url)
    {
        $this->filesystem->emptyDirectory($path);

        $fileName = $this->getFileName($package, $path);

        $processedUrl = $this->processUrl($package, $url);
        $hostname = parse_url($processedUrl, PHP_URL_HOST);

        $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->rfs, $processedUrl);
        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
        }
        $rfs = $preFileDownloadEvent->getRemoteFilesystem();

        try {
            $checksum = $package->getDistSha1Checksum();
            $cacheKey = $this->getCacheKey($package, $processedUrl);

            // use from cache if it is present and has a valid checksum or we have no checksum to check against
            if ($this->cache && (!$checksum || $checksum === $this->cache->sha1($cacheKey)) && $this->cache->copyTo($cacheKey, $fileName)) {
                $this->io->writeError('Loading from cache', false);
            } else {
                // download if cache restore failed
                if (!$this->outputProgress) {
                    $this->io->writeError('Downloading', false);
                }

                // try to download 3 times then fail hard
                $retries = 3;
                while ($retries--) {
                    try {
                        $rfs->copy($hostname, $processedUrl, $fileName, $this->outputProgress, $package->getTransportOptions());
                        break;
                    } catch (TransportException $e) {
                        // if we got an http response with a proper code, then requesting again will probably not help, abort
                        if ((0 !== $e->getCode() && !in_array($e->getCode(), array(500, 502, 503, 504))) || !$retries) {
                            throw $e;
                        }
                        $this->io->writeError('');
                        $this->io->writeError('    Download failed, retrying...', true, IOInterface::VERBOSE);
                        usleep(500000);
                    }
                }

                if (!$this->outputProgress) {
                    $this->io->writeError(' (<comment>100%</comment>)', false);
                }

                if ($this->cache) {
                    $this->lastCacheWrites[$package->getName()] = $cacheKey;
                    $this->cache->copyFrom($cacheKey, $fileName);
                }
            }

            if (!file_exists($fileName)) {
                throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                    .' directory is writable and you have internet connectivity');
            }

            if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
                throw new \UnexpectedValueException('The checksum verification of the file failed (downloaded from '.$url.')');
            }
        } catch (\Exception $e) {
            // clean up
            $this->filesystem->removeDirectory($path);
            $this->clearLastCacheWrite($package);
            throw $e;
        }

        return $fileName;
    }

    /**
     * {@inheritDoc}
     */
    public function setOutputProgress($outputProgress)
    {
        $this->outputProgress = $outputProgress;

        return $this;
    }

    protected function clearLastCacheWrite(PackageInterface $package)
    {
        if ($this->cache && isset($this->lastCacheWrites[$package->getName()])) {
            $this->cache->remove($this->lastCacheWrites[$package->getName()]);
            unset($this->lastCacheWrites[$package->getName()]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        $name = $target->getName();
        $from = $initial->getFullPrettyVersion();
        $to = $target->getFullPrettyVersion();

        $actionName = VersionParser::isUpgrade($initial->getVersion(), $target->getVersion()) ? 'Updating' : 'Downgrading';
        $this->io->writeError("  - " . $actionName . " <info>" . $name . "</info> (<comment>" . $from . "</comment> => <comment>" . $to . "</comment>): ", false);

        $this->remove($initial, $path, false);
        $this->download($target, $path, false);

        $this->io->writeError('');
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path, $output = true)
    {
        if ($output) {
            $this->io->writeError("  - Removing <info>" . $package->getName() . "</info> (<comment>" . $package->getFullPrettyVersion() . "</comment>)");
        }
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
     * @param  PackageInterface  $package package the url is coming from
     * @param  string            $url     download url
     * @throws \RuntimeException If any problem with the url
     * @return string            url
     */
    protected function processUrl(PackageInterface $package, $url)
    {
        if (!extension_loaded('openssl') && 0 === strpos($url, 'https:')) {
            throw new \RuntimeException('You must enable the openssl extension to download files via https');
        }

        if ($package->getDistReference()) {
            $url = UrlUtil::updateDistReference($this->config, $url, $package->getDistReference());
        }

        return $url;
    }

    private function getCacheKey(PackageInterface $package, $processedUrl)
    {
        // we use the complete download url here to avoid conflicting entries
        // from different packages, which would potentially allow a given package
        // in a third party repo to pre-populate the cache for the same package in
        // packagist for example.
        $cacheKey = sha1($processedUrl);

        return $package->getName().'/'.$cacheKey.'.'.$package->getDistType();
    }

    /**
     * {@inheritDoc}
     * @throws \RuntimeException
     */
    public function getLocalChanges(PackageInterface $package, $targetDir)
    {
        $prevIO = $this->io;
        $prevProgress = $this->outputProgress;

        $this->io = new NullIO;
        $this->io->loadConfiguration($this->config);
        $this->outputProgress = false;
        $e = null;

        try {
            $this->download($package, $targetDir.'_compare', false);

            $comparer = new Comparer();
            $comparer->setSource($targetDir.'_compare');
            $comparer->setUpdate($targetDir);
            $comparer->doCompare();
            $output = $comparer->getChanged(true, true);
            $this->filesystem->removeDirectory($targetDir.'_compare');
        } catch (\Exception $e) {
        }

        $this->io = $prevIO;
        $this->outputProgress = $prevProgress;

        if ($e) {
            throw $e;
        }

        return trim($output);
    }
}
