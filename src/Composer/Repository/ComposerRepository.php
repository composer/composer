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
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\DependencyResolver\Pool;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\Config;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Downloader\TransportException;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    protected $config;
    protected $repoConfig;
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
    protected $lazyProvidersUrl;
    protected $providerListing;
    protected $providers = array();
    protected $providersByUid = array();
    protected $loader;
    protected $rootAliases;
    protected $allowSslDowngrade = false;
    protected $eventDispatcher;
    protected $sourceMirrors;
    protected $distMirrors;
    private $degradedMode = false;
    private $rootData;
    private $hasPartialPackages;
    private $partialPackagesByName;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null, RemoteFilesystem $rfs = null)
    {
        parent::__construct();
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

        // force url for packagist.org to repo.packagist.org
        if (preg_match('{^(?P<proto>https?)://packagist\.org/?$}i', $this->url, $match)) {
            $this->url = $match['proto'].'://repo.packagist.org';
        }

        $this->baseUrl = rtrim(preg_replace('{(?:/[^/\\\\]+\.json)?(?:[?#].*)?$}', '', $this->url), '/');
        $this->io = $io;
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url), 'a-z0-9.$');
        $this->loader = new ArrayLoader();
        if ($rfs && $this->options) {
            $rfs = clone $rfs;
            $rfs->setOptions($this->options);
        }
        $this->rfs = $rfs ?: Factory::createRemoteFilesystem($this->io, $this->config, $this->options);
        $this->eventDispatcher = $eventDispatcher;
        $this->repoConfig = $repoConfig;
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    public function setRootAliases(array $rootAliases)
    {
        $this->rootAliases = $rootAliases;
    }

    /**
     * {@inheritDoc}
     */
    public function findPackage($name, $constraint)
    {
        if (!$this->hasProviders()) {
            return parent::findPackage($name, $constraint);
        }

        $name = strtolower($name);
        if (!$constraint instanceof ConstraintInterface) {
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($constraint);
        }

        foreach ($this->getProviderNames() as $providerName) {
            if ($name === $providerName) {
                $packages = $this->whatProvides(new Pool('dev'), $providerName);
                foreach ($packages as $package) {
                    if ($name === $package->getName()) {
                        $pkgConstraint = new Constraint('==', $package->getVersion());
                        if ($constraint->matches($pkgConstraint)) {
                            return $package;
                        }
                    }
                }
                break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findPackages($name, $constraint = null)
    {
        if (!$this->hasProviders()) {
            return parent::findPackages($name, $constraint);
        }
        // normalize name
        $name = strtolower($name);

        if (null !== $constraint && !$constraint instanceof ConstraintInterface) {
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($constraint);
        }

        $packages = array();

        foreach ($this->getProviderNames() as $providerName) {
            if ($name === $providerName) {
                $candidates = $this->whatProvides(new Pool('dev'), $providerName);
                foreach ($candidates as $package) {
                    if ($name === $package->getName()) {
                        $pkgConstraint = new Constraint('==', $package->getVersion());
                        if (null === $constraint || $constraint->matches($pkgConstraint)) {
                            $packages[] = $package;
                        }
                    }
                }
                break;
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
    public function search($query, $mode = 0, $type = null)
    {
        $this->loadRootServerFile();

        if ($this->searchUrl && $mode === self::SEARCH_FULLTEXT) {
            $url = str_replace(array('%query%', '%type%'), array($query, $type), $this->searchUrl);

            $hostname = parse_url($url, PHP_URL_HOST) ?: $url;
            $json = $this->rfs->getContents($hostname, $url, false);
            $search = JsonFile::parseJson($json, $url);

            if (empty($search['results'])) {
                return array();
            }

            $results = array();
            foreach ($search['results'] as $result) {
                // do not show virtual packages in results as they are not directly useful from a composer perspective
                if (empty($result['virtual'])) {
                    $results[] = $result;
                }
            }

            return $results;
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

        return array();
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

    public function resetPackageIds()
    {
        foreach ($this->providersByUid as $package) {
            if ($package instanceof AliasPackage) {
                $package->getAliasOf()->setId(-1);
            }
            $package->setId(-1);
        }
    }

    /**
     * @param  Pool        $pool
     * @param  string      $name          package name
     * @param  bool        $bypassFilters If set to true, this bypasses the stability filtering, and forces a recompute without cache
     * @return array|mixed
     */
    public function whatProvides(Pool $pool, $name, $bypassFilters = false)
    {
        if (isset($this->providers[$name]) && !$bypassFilters) {
            return $this->providers[$name];
        }

        if ($this->hasPartialPackages && null === $this->partialPackagesByName) {
            $this->initializePartialPackages();
        }

        if (!$this->hasPartialPackages || !isset($this->partialPackagesByName[$name])) {
            // skip platform packages, root package and composer-plugin-api
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name) || '__root__' === $name || 'composer-plugin-api' === $name) {
                return array();
            }

            if (null === $this->providerListing) {
                $this->loadProviderListings($this->loadRootServerFile());
            }

            $useLastModifiedCheck = false;
            if ($this->lazyProvidersUrl && !isset($this->providerListing[$name])) {
                $hash = null;
                $url = str_replace('%package%', $name, $this->lazyProvidersUrl);
                $cacheKey = 'provider-'.strtr($name, '/', '$').'.json';
                $useLastModifiedCheck = true;
            } elseif ($this->providersUrl) {
                // package does not exist in this repo
                if (!isset($this->providerListing[$name])) {
                    return array();
                }

                $hash = $this->providerListing[$name]['sha256'];
                $url = str_replace(array('%package%', '%hash%'), array($name, $hash), $this->providersUrl);
                $cacheKey = 'provider-'.strtr($name, '/', '$').'.json';
            } else {
                return array();
            }

            $packages = null;
            if ($cacheKey) {
                if (!$useLastModifiedCheck && $hash && $this->cache->sha256($cacheKey) === $hash) {
                    $packages = json_decode($this->cache->read($cacheKey), true);
                } elseif ($useLastModifiedCheck) {
                    if ($contents = $this->cache->read($cacheKey)) {
                        $contents = json_decode($contents, true);
                        if (isset($contents['last-modified'])) {
                            $response = $this->fetchFileIfLastModified($url, $cacheKey, $contents['last-modified']);
                            if (true === $response) {
                                $packages = $contents;
                            } elseif ($response) {
                                $packages = $response;
                            }
                        }
                    }
                }
            }

            if (!$packages) {
                try {
                    $packages = $this->fetchFile($url, $cacheKey, $hash, $useLastModifiedCheck);
                } catch (TransportException $e) {
                    // 404s are acceptable for lazy provider repos
                    if ($e->getStatusCode() === 404 && $this->lazyProvidersUrl) {
                        $packages = array('packages' => array());
                    } else {
                        throw $e;
                    }
                }
            }

            $loadingPartialPackage = false;
        } else {
            $packages = array('packages' => array('versions' => $this->partialPackagesByName[$name]));
            $loadingPartialPackage = true;
        }

        $this->providers[$name] = array();
        foreach ($packages['packages'] as $versions) {
            foreach ($versions as $version) {
                if (!$loadingPartialPackage && $this->hasPartialPackages && isset($this->partialPackagesByName[$version['name']])) {
                    continue;
                }

                // avoid loading the same objects twice
                if (isset($this->providersByUid[$version['uid']])) {
                    // skip if already assigned
                    if (!isset($this->providers[$name][$version['uid']])) {
                        // expand alias in two packages
                        if ($this->providersByUid[$version['uid']] instanceof AliasPackage) {
                            $this->providers[$name][$version['uid']] = $this->providersByUid[$version['uid']]->getAliasOf();
                            $this->providers[$name][$version['uid'].'-alias'] = $this->providersByUid[$version['uid']];
                        } else {
                            $this->providers[$name][$version['uid']] = $this->providersByUid[$version['uid']];
                        }
                        // check for root aliases
                        if (isset($this->providersByUid[$version['uid'].'-root'])) {
                            $this->providers[$name][$version['uid'].'-root'] = $this->providersByUid[$version['uid'].'-root'];
                        }
                    }
                } else {
                    if (!$bypassFilters && !$pool->isPackageAcceptable(strtolower($version['name']), VersionParser::parseStability($version['version']))) {
                        continue;
                    }

                    // load acceptable packages in the providers
                    $package = $this->createPackage($version, 'Composer\Package\CompletePackage');
                    $package->setRepository($this);

                    if ($package instanceof AliasPackage) {
                        $aliased = $package->getAliasOf();
                        $aliased->setRepository($this);

                        $this->providers[$name][$version['uid']] = $aliased;
                        $this->providers[$name][$version['uid'].'-alias'] = $package;

                        // override provider with its alias so it can be expanded in the if block above
                        $this->providersByUid[$version['uid']] = $package;
                    } else {
                        $this->providers[$name][$version['uid']] = $package;
                        $this->providersByUid[$version['uid']] = $package;
                    }

                    // handle root package aliases
                    unset($rootAliasData);

                    if (isset($this->rootAliases[$package->getName()][$package->getVersion()])) {
                        $rootAliasData = $this->rootAliases[$package->getName()][$package->getVersion()];
                    } elseif ($package instanceof AliasPackage && isset($this->rootAliases[$package->getName()][$package->getAliasOf()->getVersion()])) {
                        $rootAliasData = $this->rootAliases[$package->getName()][$package->getAliasOf()->getVersion()];
                    }

                    if (isset($rootAliasData)) {
                        $alias = $this->createAliasPackage($package, $rootAliasData['alias_normalized'], $rootAliasData['alias']);
                        $alias->setRepository($this);

                        $this->providers[$name][$version['uid'].'-root'] = $alias;
                        $this->providersByUid[$version['uid'].'-root'] = $alias;
                    }
                }
            }
        }

        $result = $this->providers[$name];

        // clean up the cache because otherwise using this puts the repo in an inconsistent state with a polluted unfiltered cache
        // which is likely not an issue but might cause hard to track behaviors depending on how the repo is used
        if ($bypassFilters) {
            foreach ($this->providers[$name] as $uid => $provider) {
                unset($this->providersByUid[$uid]);
            }
            unset($this->providers[$name]);
        }

        return $result;
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
                    $this->distMirrors[] = array(
                        'url' => $this->canonicalizeUrl($mirror['dist-url']),
                        'preferred' => !empty($mirror['preferred']),
                    );
                }
            }
        }

        if (!empty($data['providers-lazy-url'])) {
            $this->lazyProvidersUrl = $this->canonicalizeUrl($data['providers-lazy-url']);
            $this->hasProviders = true;

            $this->hasPartialPackages = !empty($data['packages']) && is_array($data['packages']);
        }

        if ($this->allowSslDowngrade) {
            $this->url = str_replace('https://', 'http://', $this->url);
            $this->baseUrl = str_replace('https://', 'http://', $this->baseUrl);
        }

        if (!empty($data['providers-url'])) {
            $this->providersUrl = $this->canonicalizeUrl($data['providers-url']);
            $this->hasProviders = true;
        }

        if (!empty($data['providers']) || !empty($data['providers-includes'])) {
            $this->hasProviders = true;
        }

        // force values for packagist
        if (preg_match('{^https?://repo\.packagist\.org/?$}i', $this->url) && !empty($this->repoConfig['force-lazy-providers'])) {
            $this->url = 'https://repo.packagist.org';
            $this->baseUrl = 'https://repo.packagist.org';
            $this->lazyProvidersUrl = $this->canonicalizeUrl('https://repo.packagist.org/p/%package%.json');
            $this->providersUrl = null;
        } elseif (!empty($this->repoConfig['force-lazy-providers'])) {
            $this->lazyProvidersUrl = $this->canonicalizeUrl('/p/%package%.json');
            $this->providersUrl = null;
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

    protected function createPackage(array $data, $class = 'Composer\Package\CompletePackage')
    {
        try {
            if (!isset($data['notification-url'])) {
                $data['notification-url'] = $this->notifyUrl;
            }

            $package = $this->loader->load($data, $class);
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

    protected function fetchFile($filename, $cacheKey = null, $sha256 = null, $storeLastModifiedTime = false)
    {
        if (null === $cacheKey) {
            $cacheKey = $filename;
            $filename = $this->baseUrl.'/'.$filename;
        }

        // url-encode $ signs in URLs as bad proxies choke on them
        if (($pos = strpos($filename, '$')) && preg_match('{^https?://.*}i', $filename)) {
            $filename = substr($filename, 0, $pos) . '%24' . substr($filename, $pos + 1);
        }

        $retries = 3;
        while ($retries--) {
            try {
                $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->rfs, $filename);
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                }

                $hostname = parse_url($filename, PHP_URL_HOST) ?: $filename;
                $rfs = $preFileDownloadEvent->getRemoteFilesystem();

                $json = $rfs->getContents($hostname, $filename, false);
                if ($sha256 && $sha256 !== hash('sha256', $json)) {
                    // undo downgrade before trying again if http seems to be hijacked or modifying content somehow
                    if ($this->allowSslDowngrade) {
                        $this->url = str_replace('http://', 'https://', $this->url);
                        $this->baseUrl = str_replace('http://', 'https://', $this->baseUrl);
                        $filename = str_replace('http://', 'https://', $filename);
                    }

                    if ($retries) {
                        usleep(100000);

                        continue;
                    }

                    // TODO use scarier wording once we know for sure it doesn't do false positives anymore
                    throw new RepositorySecurityException('The contents of '.$filename.' do not match its signature. This could indicate a man-in-the-middle attack or e.g. antivirus software corrupting files. Try running composer again and report this if you think it is a mistake.');
                }

                $data = JsonFile::parseJson($json, $filename);
                RemoteFilesystem::outputWarnings($this->io, $this->url, $data);

                if ($cacheKey) {
                    if ($storeLastModifiedTime) {
                        $lastModifiedDate = $rfs->findHeaderValue($rfs->getLastHeaders(), 'last-modified');
                        if ($lastModifiedDate) {
                            $data['last-modified'] = $lastModifiedDate;
                            $json = json_encode($data);
                        }
                    }
                    $this->cache->write($cacheKey, $json);
                }

                break;
            } catch (\Exception $e) {
                if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                    throw $e;
                }

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

    protected function fetchFileIfLastModified($filename, $cacheKey, $lastModifiedTime)
    {
        $retries = 3;
        while ($retries--) {
            try {
                $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->rfs, $filename);
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                }

                $hostname = parse_url($filename, PHP_URL_HOST) ?: $filename;
                $rfs = $preFileDownloadEvent->getRemoteFilesystem();
                $options = array('http' => array('header' => array('If-Modified-Since: '.$lastModifiedTime)));
                $json = $rfs->getContents($hostname, $filename, false, $options);
                if ($json === '' && $rfs->findStatusCode($rfs->getLastHeaders()) === 304) {
                    return true;
                }

                $data = JsonFile::parseJson($json, $filename);
                RemoteFilesystem::outputWarnings($this->io, $this->url, $data);

                $lastModifiedDate = $rfs->findHeaderValue($rfs->getLastHeaders(), 'last-modified');
                if ($lastModifiedDate) {
                    $data['last-modified'] = $lastModifiedDate;
                    $json = json_encode($data);
                }
                $this->cache->write($cacheKey, $json);

                return $data;
            } catch (\Exception $e) {
                if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                    throw $e;
                }

                if ($retries) {
                    usleep(100000);
                    continue;
                }

                if (!$this->degradedMode) {
                    $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
                    $this->io->writeError('<warning>'.$this->url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
                }
                $this->degradedMode = true;

                return true;
            }
        }
    }

    /**
     * This initializes the packages key of a partial packages.json that contain some packages inlined + a providers-lazy-url
     *
     * This should only be called once
     */
    private function initializePartialPackages()
    {
        $rootData = $this->loadRootServerFile();

        $this->partialPackagesByName = array();
        foreach ($rootData['packages'] as $package => $versions) {
            $package = strtolower($package);
            foreach ($versions as $version) {
                $this->partialPackagesByName[$package][] = $version;
                if (!empty($version['provide']) && is_array($version['provide'])) {
                    foreach ($version['provide'] as $provided => $providedVersion) {
                        $this->partialPackagesByName[strtolower($provided)][] = $version;
                    }
                }
                if (!empty($version['replace']) && is_array($version['replace'])) {
                    foreach ($version['replace'] as $provided => $providedVersion) {
                        $this->partialPackagesByName[strtolower($provided)][] = $version;
                    }
                }
            }
        }

        // wipe rootData as it is fully consumed at this point and this saves some memory
        $this->rootData = true;
    }
}
