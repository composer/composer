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
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use React\Promise\PromiseInterface;

/**
 * Base downloader for files
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class FileDownloader implements DownloaderInterface
{
    protected $io;
    protected $config;
    protected $rfs;
    protected $filesystem;
    protected $cache;
    protected $outputProgress = true;

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
        $this->rfs = $rfs ?: new RemoteFilesystem($io, $config);
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
    public function download(PackageInterface $package, $path, $loop = null)
    {
        if (!$package->getDistUrl()) {
            throw new \InvalidArgumentException('The given package is missing url information');
        }

        $this->io->writeError("  - Installing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");

        $urls = $package->getDistUrls();

        return $this->retryFileDownload($urls, $package, $path, $loop);
    }

    private function retryFileDownload(&$urls, $package, $path, $loop, \Exception $e = null)
    {
        if (isset($e)) {
            if ($this->io->isDebug()) {
                $this->io->writeError('');
                $this->io->writeError('Failed: ['.get_class($e).'] '.$e->getCode().': '.$e->getMessage());
            } elseif ($urls) {
                $this->io->writeError('');
                $this->io->writeError('    Failed, trying the next URL ('.$e->getCode().': '.$e->getMessage().')');
            }
        }
        if (!$urls) {
            throw $e;
        }
        $url = array_shift($urls);

        try {
            $result = $this->doDownload($package, $path, $url, $loop);
            if ($result instanceof PromiseInterface) {
                return $result->then(function ($result) {
                    $this->io->writeError('');
                    return $result;
                })->then(null, function (\Exception $e) use (&$urls, $package, $path, $loop) {
                    return $this->retryFileDownload($urls, $package, $path, $loop, $e);
                });
            } else {
                $this->io->writeError('');
                return $result;
            }
        } catch (\Exception $e) {
            return $this->retryFileDownload($urls, $package, $path, $loop, $e);
        }
    }

    protected function doDownload(PackageInterface $package, $path, $url, $loop = null)
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
            $cacheKey = $this->getCacheKey($package);
            $result = $fileName;

            // download if we don't have it in cache or the cache is invalidated
            if (!$this->cache || ($checksum && $checksum !== $this->cache->sha1($cacheKey)) || !$this->cache->copyTo($cacheKey, $fileName)) {
                if (!$this->outputProgress) {
                    $this->io->writeError('    Downloading');
                }

                // try to download 3 times then fail hard
                $result = $this->retryCopy(3, $rfs, $hostname, $processedUrl, $fileName, $package, $loop);
            } else {
                $this->io->writeError('    Loading from cache');
            }
            $result = $this->onCopy($result, $cacheKey, $fileName, $url, $checksum);
            if ($result instanceof PromiseInterface) {
                $result = $result->then(null, function (\Exception $e) use ($path, $package) {
                    // clean up
                    $this->filesystem->removeDirectory($path);
                    $this->clearCache($package, $path);
                    throw $e;
                });
            }
            return $result;
        } catch (\Exception $e) {
            // clean up
            $this->filesystem->removeDirectory($path);
            $this->clearCache($package, $path);
            throw $e;
        }
    }

    private function retryCopy($retries, $rfs, $hostname, $processedUrl, $fileName, $package, $loop, \Exception $e = null)
    {
        if ($e) {
            // if we got an http response with a proper code, then requesting again will probably not help, abort
            if (!$retries || !$e instanceof TransportException || (0 !== $e->getCode() && !in_array($e->getCode(), array(500, 502, 503, 504)))) {
                throw $e;
            }
            if ($this->io->isVerbose()) {
                $this->io->writeError('    Download failed, retrying...');
            }
            usleep(500000);
        }
        try {
            $result = $rfs->copy($hostname, $processedUrl, $fileName, $this->outputProgress, $package->getTransportOptions(), $loop);
            if ($result instanceof PromiseInterface) {
                return $result->then(null, function (\Exception $e) use ($retries, $rfs, $hostname, $processedUrl, $fileName, $package, $loop, $e) {
                    return $this->retryCopy(--$retries, $rfs, $hostname, $processedUrl, $fileName, $package, $loop, $e);
                });
            } else {
                return $result;
            }
        } catch (\Exception $e) {
            return $this->retryCopy(--$retries, $rfs, $hostname, $processedUrl, $fileName, $package, $loop, $e);
        }
    }

    private function onCopy($result, $cacheKey, $fileName, $url, $checksum)
    {
        if ($result instanceof PromiseInterface) {
            return $result->then(function ($result) use ($cacheKey, $fileName, $url, $checksum) {
                return $this->onCopy($result, $cacheKey, $fileName, $url, $checksum);
            });
        }
        if ($this->cache) {
            $this->cache->copyFrom($cacheKey, $fileName);
        }
        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity');
        }
        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification of the file failed (downloaded from '.$url.')');
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

    protected function clearCache(PackageInterface $package, $path)
    {
        if ($this->cache) {
            $fileName = $this->getFileName($package, $path);
            $this->cache->remove($this->getCacheKey($package));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path, $loop = null)
    {
        $this->remove($initial, $path);
        return $this->download($target, $path, $loop);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->io->writeError("  - Removing <info>" . $package->getName() . "</info> (<comment>" . VersionParser::formatVersion($package) . "</comment>)");
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

    private function getCacheKey(PackageInterface $package)
    {
        if (preg_match('{^[a-f0-9]{40}$}', $package->getDistReference())) {
            return $package->getName().'/'.$package->getDistReference().'.'.$package->getDistType();
        }

        return $package->getName().'/'.$package->getVersion().'-'.$package->getDistReference().'.'.$package->getDistType();
    }
}
