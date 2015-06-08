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

namespace Composer\Repository;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\DependencyResolver\Pool;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\EventDispatcher\EventDispatcher;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository
{
    protected $config;
    protected $options;
    protected $url;
    protected $baseUrl;
    protected $io;
    protected $rfs;
    protected $cache;
    protected $notifyUrl;
    protected $searchUrl;
    protected $hasProviders = false;
    protected $providersUrl;
    protected $loadedMap = array();
    protected $lazyProvidersUrl;
    protected $providerListing;
    protected $loader;
    protected $rootAliases;
    protected $allowSslDowngrade = false;
    protected $eventDispatcher;
    protected $sourceMirrors;
    protected $distMirrors;
    private $degradedMode = false;
    private $rootData;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null)
    {
        if (!preg_match('{^[\w.]+\??://}', $repoConfig['url'])) {
            // assume http as the default protocol
            $repoConfig['url'] = 'http://'.$repoConfig['url'];
        }
        $repoConfig['url'] = rtrim($repoConfig['url'], '/');

        if ('https?' === substr($repoConfig['url'], 0, 6)) {
            $repoConfig['url'] = (extension_loaded('openssl') ? 'https' : 'http') . substr($repoConfig['url'], 6);
        }

        $urlBits = parse_url($repoConfig['url']);
        if ($urlBits === false || empty($urlBits['scheme'])) {
            throw new \UnexpectedValueException('Invalid url given for Composer repository: '.$repoConfig['url']);
        }

        if (!isset($repoConfig['options'])) {
            $repoConfig['options'] = array();
        }
        if (isset($repoConfig['allow_ssl_downgrade']) && true === $repoConfig['allow_ssl_downgrade']) {
            $this->allowSslDowngrade = true;
        }

        $this->config = $config;
        $this->options = $repoConfig['options'];
        $this->url = $repoConfig['url'];
        $this->baseUrl = rtrim(preg_replace('{^(.*)(?:/[^/\\]+.json)?(?:[?#].*)?$}', '$1', $this->url), '/');
        $this->io = $io;
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url), 'a-z0-9.$');
        $this->loader = new ArrayLoader();
        $this->rfs = new RemoteFilesystem($this->io, $this->config, $this->options);
        $this->eventDispatcher = $eventDispatcher;
    }

    public function setRootAliases(array $rootAliases)
    {
        $this->rootAliases = $rootAliases;
    }

    public function getRootAliases()
    {
        return $this->rootAliases;
    }

    /**
     * Load all packages with given names and dependencies
     *
     * @param array $packageNames
     * @param callable|null $acceptableCallback Callback to filter packages
     *
     * @return array The loaded package objects
     */
    public function loadRecursively(array $packageNames, $acceptableCallback)
    {
        $workQueue = new \SplQueue;

        foreach ($packageNames as $packageName) {
            $workQueue->enqueue($packageName);
        }

        $loadedPackages = array();

        while (!$workQueue->isEmpty()) {
            $packageName = $workQueue->dequeue();
            if (isset($this->loadedMap[$packageName])) {
                continue;
            }

            $this->loadedMap[$packageName] = true;

            $packages = $this->loadName($packageName, $acceptableCallback);

            foreach ($packages as $package) {
                $loadedPackages[] = $package;
                $requires = $package->getRequires();
                foreach ($requires as $link) {
                    $workQueue->enqueue($link->getTarget());
                }
            }
        }

        return $loadedPackages;
    }

    /**
     * {@inheritDoc}
     */
    public function findPackage($name, $version)
    {
        if (!$this->hasProviders()) {
            return parent::findPackage($name, $version);
        }
        // normalize version & name
        $versionParser = new VersionParser();
        $version = $versionParser->normalize($version);
        $name = strtolower($name);

        foreach ($this->getProviderNames() as $providerName) {
            if ($name === $providerName) {
                $packages = $this->loadName($providerName, null, false);
                foreach ($packages as $package) {
                    if ($name == $package->getName() && $version === $package->getVersion()) {
                        return $package;
                    }
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findPackages($name, $version = null)
    {
        if (!$this->hasProviders()) {
            return parent::findPackages($name, $version);
        }
        // normalize name
        $name = strtolower($name);

        // normalize version
        if (null !== $version) {
            $versionParser = new VersionParser();
            $version = $versionParser->normalize($version);
        }

        $packages = array();

        foreach ($this->getProviderNames() as $providerName) {
            if ($name === $providerName) {
                $packages = $this->loadName($providerName, null, false);
                foreach ($packages as $package) {
                    if ($name == $package->getName() && (null === $version || $version === $package->getVersion())) {
                        $packages[] = $package;
                    }
                }
            }
        }

        return $packages;
    }

    public function getPackages()
    {
        if ($this->hasProviders()) {
            throw new \LogicException('Composer repositories that have providers can not load the complete list of packages, use getProviderNames instead.');
        }

        return parent::getPackages();
    }

    /**
     * {@inheritDoc}
     */
    public function search($query, $mode = 0)
    {
        $this->loadRootServerFile();

        if ($this->searchUrl && $mode === self::SEARCH_FULLTEXT) {
            $url = str_replace('%query%', $query, $this->searchUrl);

            $hostname = parse_url($url, PHP_URL_HOST) ?: $url;
            $json = $this->rfs->getContents($hostname, $url, false);
            $results = JsonFile::parseJson($json, $url);

            return $results['results'];
        }

        if ($this->hasProviders()) {
            $results = array();
            $regex = '{(?:'.implode('|', preg_split('{\s+}', $query)).')}i';

            foreach ($this->getProviderNames() as $name) {
                if (preg_match($regex, $name)) {
                    $results[] = array('name' => $name);
                }
            }

            return $results;
        }

        return parent::search($query, $mode);
    }

    public function getProviderNames()
    {
        $this->loadRootServerFile();

        if (null === $this->providerListing) {
            $this->loadProviderListings($this->loadRootServerFile());
        }

        if ($this->lazyProvidersUrl) {
            // Can not determine list of provided packages for lazy repositories
            return array();
        }

        if ($this->providersUrl) {
            return array_keys($this->providerListing);
        }

        // BC handling for old providers-includes
        $providers = array();
        foreach (array_keys($this->providerListing) as $provider) {
            $providers[] = substr($provider, 2, -5);
        }

        return $providers;
    }

    protected function configurePackageTransportOptions(PackageInterface $package)
    {
        foreach ($package->getDistUrls() as $url) {
            if (strpos($url, $this->baseUrl) === 0) {
                $package->setTransportOptions($this->options);

                return;
            }
        }
    }

    public function hasProviders()
    {
        $this->loadRootServerFile();

        return $this->hasProviders;
    }

    /**
     * Loads package data for a given package name or provider name
     *
     * @param string $name
     * @param callable|null $acceptableCallback A callback to check if a package should be loaded
     * @param bool $exactMatch Whether packages only providing the name should be ignored
     *
     * @return array All packages that were loaded
     */
    protected function loadName($name, $acceptableCallback, $exactMatch = true)
    {
        // skip platform packages
        if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name) || '__root__' === $name) {
            return array();
        }

        if (null === $this->providerListing) {
            $this->loadProviderListings($this->loadRootServerFile());
        }

        if ($this->lazyProvidersUrl && !isset($this->providerListing[$name])) {
            $hash = null;
            $url = str_replace('%package%', $name, $this->lazyProvidersUrl);
            $cacheKey = false;
        } elseif ($this->providersUrl) {
            // package does not exist in this repo
            if (!isset($this->providerListing[$name])) {
                return array();
            }

            $hash = $this->providerListing[$name]['sha256'];
            $url = str_replace(array('%package%', '%hash%'), array($name, $hash), $this->providersUrl);
            $cacheKey = 'provider-'.strtr($name, '/', '$').'.json';
        } else {
            // BC handling for old providers-includes
            $url = 'p/'.$name.'.json';

            // package does not exist in this repo
            if (!isset($this->providerListing[$url])) {
                return array();
            }
            $hash = $this->providerListing[$url]['sha256'];
            $cacheKey = null;
        }

        if ($cacheKey && $this->cache->sha256($cacheKey) === $hash) {
            $packages = json_decode($this->cache->read($cacheKey), true);
        } else {
            $packages = $this->fetchFile($url, $cacheKey, $hash);
        }

        $loadedPackages = array();
        foreach ($packages['packages'] as $packageName => $versions) {
            if ($exactMatch && $packageName !== $name) {
                continue;
            }

            foreach ($versions as $version) {
                if ($acceptableCallback && !call_user_func(
                    $acceptableCallback, strtolower($version['name']), VersionParser::parseStability($version['version'])
                )) {
                    continue;
                }

                // load acceptable packages in the providers
                $package = $this->createPackage($version, 'Composer\Package\Package');
                $this->addPackage($package);

                $loadedPackages[] = $package;

                if ($package instanceof AliasPackage) {
                    $loadedPackages[] = $package->getAliasOf();
                }
            }
        }

        return $loadedPackages;
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize()
    {
        parent::initialize();

        $repoData = $this->loadDataFromServer();

        foreach ($repoData as $package) {
            $this->addPackage($this->createPackage($package, 'Composer\Package\CompletePackage'));
        }
    }

    /**
     * Adds a new package to the repository
     *
     * @param PackageInterface $package
     */
    public function addPackage(PackageInterface $package)
    {
        parent::addPackage($package);
        $this->configurePackageTransportOptions($package);
    }

    protected function loadRootServerFile()
    {
        if (null !== $this->rootData) {
            return $this->rootData;
        }

        if (!extension_loaded('openssl') && 'https' === substr($this->url, 0, 5)) {
            throw new \RuntimeException('You must enable the openssl extension in your php.ini to load information from '.$this->url);
        }

        $jsonUrlParts = parse_url($this->url);

        if (isset($jsonUrlParts['path']) && false !== strpos($jsonUrlParts['path'], '.json')) {
            $jsonUrl = $this->url;
        } else {
            $jsonUrl = $this->url . '/packages.json';
        }

        $data = $this->fetchFile($jsonUrl, 'packages.json');

        if (!empty($data['notify-batch'])) {
            $this->notifyUrl = $this->canonicalizeUrl($data['notify-batch']);
        } elseif (!empty($data['notify_batch'])) {
            // TODO remove this BC notify_batch support
            $this->notifyUrl = $this->canonicalizeUrl($data['notify_batch']);
        } elseif (!empty($data['notify'])) {
            $this->notifyUrl = $this->canonicalizeUrl($data['notify']);
        }

        if (!empty($data['search'])) {
            $this->searchUrl = $this->canonicalizeUrl($data['search']);
        }

        if (!empty($data['mirrors'])) {
            foreach ($data['mirrors'] as $mirror) {
                if (!empty($mirror['git-url'])) {
                    $this->sourceMirrors['git'][] = array('url' => $mirror['git-url'], 'preferred' => !empty($mirror['preferred']));
                }
                if (!empty($mirror['hg-url'])) {
                    $this->sourceMirrors['hg'][] = array('url' => $mirror['hg-url'], 'preferred' => !empty($mirror['preferred']));
                }
                if (!empty($mirror['dist-url'])) {
                    $this->distMirrors[] = array('url' => $mirror['dist-url'], 'preferred' => !empty($mirror['preferred']));
                }
            }
        }

        if (!empty($data['warning'])) {
            $this->io->writeError('<warning>Warning from '.$this->url.': '.$data['warning'].'</warning>');
        }

        if (!empty($data['providers-lazy-url'])) {
            $this->lazyProvidersUrl = $this->canonicalizeUrl($data['providers-lazy-url']);
            $this->hasProviders = true;
        }

        if ($this->allowSslDowngrade) {
            $this->url = str_replace('https://', 'http://', $this->url);
        }

        if (!empty($data['providers-url'])) {
            $this->providersUrl = $this->canonicalizeUrl($data['providers-url']);
            $this->hasProviders = true;
        }

        if (!empty($data['providers']) || !empty($data['providers-includes'])) {
            $this->hasProviders = true;
        }

        return $this->rootData = $data;
    }

    protected function canonicalizeUrl($url)
    {
        if ('/' === $url[0]) {
            return preg_replace('{(https?://[^/]+).*}i', '$1' . $url, $this->url);
        }

        return $url;
    }

    protected function loadDataFromServer()
    {
        $data = $this->loadRootServerFile();

        return $this->loadIncludes($data);
    }

    protected function loadProviderListings($data)
    {
        if (isset($data['providers'])) {
            if (!is_array($this->providerListing)) {
                $this->providerListing = array();
            }
            $this->providerListing = array_merge($this->providerListing, $data['providers']);
        }

        if ($this->providersUrl && isset($data['provider-includes'])) {
            $includes = $data['provider-includes'];
            foreach ($includes as $include => $metadata) {
                $url = $this->baseUrl . '/' . str_replace('%hash%', $metadata['sha256'], $include);
                $cacheKey = str_replace(array('%hash%','$'), '', $include);
                if ($this->cache->sha256($cacheKey) === $metadata['sha256']) {
                    $includedData = json_decode($this->cache->read($cacheKey), true);
                } else {
                    $includedData = $this->fetchFile($url, $cacheKey, $metadata['sha256']);
                }

                $this->loadProviderListings($includedData);
            }
        } elseif (isset($data['providers-includes'])) {
            // BC layer for old-style providers-includes
            $includes = $data['providers-includes'];
            foreach ($includes as $include => $metadata) {
                if ($this->cache->sha256($include) === $metadata['sha256']) {
                    $includedData = json_decode($this->cache->read($include), true);
                } else {
                    $includedData = $this->fetchFile($include, null, $metadata['sha256']);
                }

                $this->loadProviderListings($includedData);
            }
        }
    }

    protected function loadIncludes($data)
    {
        $packages = array();

        // legacy repo handling
        if (!isset($data['packages']) && !isset($data['includes'])) {
            foreach ($data as $pkg) {
                foreach ($pkg['versions'] as $metadata) {
                    $packages[] = $metadata;
                }
            }

            return $packages;
        }

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $package => $versions) {
                foreach ($versions as $version => $metadata) {
                    $packages[] = $metadata;
                }
            }
        }

        if (isset($data['includes'])) {
            foreach ($data['includes'] as $include => $metadata) {
                if ($this->cache->sha1($include) === $metadata['sha1']) {
                    $includedData = json_decode($this->cache->read($include), true);
                } else {
                    $includedData = $this->fetchFile($include);
                }
                $packages = array_merge($packages, $this->loadIncludes($includedData));
            }
        }

        return $packages;
    }

    protected function createPackage(array $data, $class)
    {
        try {
            if (!isset($data['notification-url'])) {
                $data['notification-url'] = $this->notifyUrl;
            }

            $package = $this->loader->load($data, 'Composer\Package\CompletePackage');
            if (isset($this->sourceMirrors[$package->getSourceType()])) {
                $package->setSourceMirrors($this->sourceMirrors[$package->getSourceType()]);
            }
            $package->setDistMirrors($this->distMirrors);
            $this->configurePackageTransportOptions($package);

            return $package;
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not load package '.(isset($data['name']) ? $data['name'] : json_encode($data)).' in '.$this->url.': ['.get_class($e).'] '.$e->getMessage(), 0, $e);
        }
    }

    protected function fetchFile($filename, $cacheKey = null, $sha256 = null)
    {
        if (null === $cacheKey) {
            $cacheKey = $filename;
            $filename = $this->baseUrl.'/'.$filename;
        }

        $retries = 3;
        while ($retries--) {
            try {
                $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->rfs, $filename);
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                }

                $hostname = parse_url($filename, PHP_URL_HOST) ?: $filename;
                $json = $preFileDownloadEvent->getRemoteFilesystem()->getContents($hostname, $filename, false);
                if ($sha256 && $sha256 !== hash('sha256', $json)) {
                    if ($retries) {
                        usleep(100000);

                        continue;
                    }

                    // TODO use scarier wording once we know for sure it doesn't do false positives anymore
                    throw new RepositorySecurityException('The contents of '.$filename.' do not match its signature. This should indicate a man-in-the-middle attack. Try running composer again and report this if you think it is a mistake.');
                }
                $data = JsonFile::parseJson($json, $filename);
                if ($cacheKey) {
                    $this->cache->write($cacheKey, $json);
                }

                break;
            } catch (\Exception $e) {
                if ($retries) {
                    usleep(100000);
                    continue;
                }

                if ($e instanceof RepositorySecurityException) {
                    throw $e;
                }

                if ($cacheKey && ($contents = $this->cache->read($cacheKey))) {
                    if (!$this->degradedMode) {
                        $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
                        $this->io->writeError('<warning>'.$this->url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
                    }
                    $this->degradedMode = true;
                    $data = JsonFile::parseJson($contents, $this->cache->getRoot().$cacheKey);

                    break;
                }

                throw $e;
            }
        }

        return $data;
    }
}
