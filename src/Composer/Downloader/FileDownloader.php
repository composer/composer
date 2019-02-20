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
use Composer\Util\HttpDownloader;
use Composer\Util\Url as UrlUtil;
use Composer\Downloader\TransportException;

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
    protected $httpDownloader;
    protected $filesystem;
    protected $cache;
    /**
     * @private this is only public for php 5.3 support in closures
     */
    public $lastCacheWrites = array();
    private $eventDispatcher;

    /**
     * Constructor.
     *
     * @param IOInterface      $io              The IO instance
     * @param Config           $config          The config
     * @param HttpDownloader   $httpDownloader  The remote filesystem
     * @param EventDispatcher  $eventDispatcher The event dispatcher
     * @param Cache            $cache           Cache instance
     * @param Filesystem       $filesystem      The filesystem
     */
    public function __construct(IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null, Cache $cache = null, Filesystem $filesystem = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->eventDispatcher = $eventDispatcher;
        $this->httpDownloader = $httpDownloader;
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

        $retries = 3;
        $urls = $package->getDistUrls();
        foreach ($urls as $index => $url) {
            $processedUrl = $this->processUrl($package, $url);
            $urls[$index] = array(
                'base' => $url,
                'processed' => $processedUrl,
                'cacheKey' => $this->getCacheKey($package, $processedUrl)
            );
        }

        $this->filesystem->emptyDirectory($path);
        $fileName = $this->getFileName($package, $path);

        $io = $this->io;
        $cache = $this->cache;
        $httpDownloader = $this->httpDownloader;
        $eventDispatcher = $this->eventDispatcher;
        $filesystem = $this->filesystem;
        $self = $this;

        $accept = null;
        $reject = null;
        $download = function () use ($io, $output, $httpDownloader, $cache, $eventDispatcher, $package, $fileName, $path, &$urls, &$accept, &$reject) {
            $url = reset($urls);

            if ($eventDispatcher) {
                $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $httpDownloader, $url['processed']);
                $eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
            }

            $checksum = $package->getDistSha1Checksum();
            $cacheKey = $url['cacheKey'];

            // use from cache if it is present and has a valid checksum or we have no checksum to check against
            if ($cache && (!$checksum || $checksum === $cache->sha1($cacheKey)) && $cache->copyTo($cacheKey, $fileName)) {
                if ($output) {
                    $io->writeError("  - Loading <info>" . $package->getName() . "</info> (<comment>" . $package->getFullPrettyVersion() . "</comment>) from cache");
                }
                $result = \React\Promise\resolve($fileName);
            } else {
                if ($output) {
                    $io->writeError("  - Downloading <info>" . $package->getName() . "</info> (<comment>" . $package->getFullPrettyVersion() . "</comment>)");
                }

                $result = $httpDownloader->addCopy($url['processed'], $fileName, $package->getTransportOptions())
                    ->then($accept, $reject);
            }

            return $result->then(function ($result) use ($fileName, $checksum, $url) {
                // in case of retry, the first call's Promise chain finally calls this twice at the end,
                // once with $result being the returned $fileName from $accept, and then once for every
                // failed request with a null result, which can be skipped.
                if (null === $result) {
                    return $fileName;
                }

                if (!file_exists($fileName)) {
                    throw new \UnexpectedValueException($url['base'].' could not be saved to '.$fileName.', make sure the'
                        .' directory is writable and you have internet connectivity');
                }

                if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
                    throw new \UnexpectedValueException('The checksum verification of the file failed (downloaded from '.$url['base'].')');
                }

                return $fileName;
            });
        };

        $accept = function ($response) use ($io, $cache, $package, $fileName, $path, $self, &$urls) {
            $url = reset($urls);
            $cacheKey = $url['cacheKey'];

            if ($cache) {
                $self->lastCacheWrites[$package->getName()] = $cacheKey;
                $cache->copyFrom($cacheKey, $fileName);
            }

            $response->collect();

            return $fileName;
        };

        $reject = function ($e) use ($io, &$urls, $download, $fileName, $path, $package, &$retries, $filesystem, $self) {
            // clean up
            $filesystem->removeDirectory($path);
            $self->clearLastCacheWrite($package);

            if ($e instanceof TransportException) {
                // if we got an http response with a proper code, then requesting again will probably not help, abort
                if ((0 !== $e->getCode() && !in_array($e->getCode(), array(500, 502, 503, 504))) || !$retries) {
                    $retries = 0;
                }
            }

            // special error code returned when network is being artificially disabled
            if ($e instanceof TransportException && $e->getStatusCode() === 499) {
                $retries = 0;
                $urls = array();
            }

            if ($retries) {
                usleep(500000);
                $retries--;

                return $download();
            }

            array_shift($urls);
            if ($urls) {
                if ($io->isDebug()) {
                    $io->writeError('    Failed downloading '.$package->getName().': ['.get_class($e).'] '.$e->getCode().': '.$e->getMessage());
                    $io->writeError('    Trying the next URL for '.$package->getName());
                } elseif (count($urls)) {
                    $io->writeError('    Failed downloading '.$package->getName().', trying the next URL ('.$e->getCode().': '.$e->getMessage().')');
                }

                $retries = 3;
                usleep(100000);

                return $download();
            }

            throw $e;
        };

        return $download();
    }

    /**
     * {@inheritDoc}
     */
    public function install(PackageInterface $package, $path, $output = true)
    {
        if ($output) {
            $this->io->writeError("  - Installing <info>" . $package->getName() . "</info> (<comment>" . $package->getFullPrettyVersion() . "</comment>)");
        }

        $this->filesystem->ensureDirectoryExists($path);
        $this->filesystem->rename($this->getFileName($package, $path), $path . pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_BASENAME));
    }

    /**
     * TODO mark private in v3
     * @protected This is public due to PHP 5.3
     */
    public function clearLastCacheWrite(PackageInterface $package)
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
        $this->install($target, $path, false);

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
        return $path.'_'.pathinfo(parse_url($package->getDistUrl(), PHP_URL_PATH), PATHINFO_BASENAME);
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

        $this->io = new NullIO;
        $this->io->loadConfiguration($this->config);
        $e = null;

        try {
            $res = $this->download($package, $targetDir.'_compare', false);
            $this->httpDownloader->wait();
            $res = $this->install($package, $targetDir.'_compare', false);

            $comparer = new Comparer();
            $comparer->setSource($targetDir.'_compare');
            $comparer->setUpdate($targetDir);
            $comparer->doCompare();
            $output = $comparer->getChanged(true, true);
            $this->filesystem->removeDirectory($targetDir.'_compare');
        } catch (\Exception $e) {
        }

        $this->io = $prevIO;

        if ($e) {
            throw $e;
        }

        return trim($output);
    }
}
