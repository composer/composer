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
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Downloader\TransportException;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\EmptyConstraint;

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
    protected $httpDownloader;
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
    /**
     * TODO v3 should make this private once we can drop PHP 5.3 support
     * @private
     */
    public $versionParser;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
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
        $this->versionParser = new VersionParser();
        $this->loader = new ArrayLoader($this->versionParser);
        $this->httpDownloader = $httpDownloader;
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
        // this call initializes loadRootServerFile which is needed for the rest below to work
        $hasProviders = $this->hasProviders();

        $name = strtolower($name);
        if (!$constraint instanceof ConstraintInterface) {
            $constraint = $this->versionParser->parseConstraints($constraint);
        }

        // TODO we need a new way for the repo to report this v2 protocol somehow
        if ($this->lazyProvidersUrl) {
            return $this->loadAsyncPackages(array($name => $constraint), function ($name, $stability) {
                return true;
            });
        }
        if (!$hasProviders) {
            return parent::findPackage($name, $constraint);
        }

        foreach ($this->getProviderNames() as $providerName) {
            if ($name === $providerName) {
                $packages = $this->whatProvides($providerName);
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
        // this call initializes loadRootServerFile which is needed for the rest below to work
        $hasProviders = $this->hasProviders();

        // TODO we need a new way for the repo to report this v2 protocol somehow
        if ($this->lazyProvidersUrl) {
            return $this->loadAsyncPackages(array($name => $constraint ?: new EmptyConstraint()), function ($name, $stability) {
                return true;
            });
        }
        if (!$hasProviders) {
            return parent::findPackages($name, $constraint);
        }

        // normalize name
        $name = strtolower($name);

        if (null !== $constraint && !$constraint instanceof ConstraintInterface) {
            $constraint = $this->versionParser->parseConstraints($constraint);
        }

        $packages = array();

        foreach ($this->getProviderNames() as $providerName) {
            if ($name === $providerName) {
                $candidates = $this->whatProvides($providerName);
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

    public function loadPackages(array $packageNameMap, $isPackageAcceptableCallable)
    {
        // this call initializes loadRootServerFile which is needed for the rest below to work
        $hasProviders = $this->hasProviders();

        // TODO we need a new way for the repo to report this v2 protocol somehow
        if ($this->lazyProvidersUrl) {
            return $this->loadAsyncPackages($packageNameMap, $isPackageAcceptableCallable);
        }
        if (!$hasProviders) {
            return parent::loadPackages($packageNameMap, $isPackageAcceptableCallable);
        }

        $packages = array();
        foreach ($packageNameMap as $name => $constraint) {
            $matches = array();
            $candidates = $this->whatProvides($name, false, $isPackageAcceptableCallable);
            foreach ($candidates as $candidate) {
                if ($candidate->getName() === $name && (!$constraint || $constraint->matches(new Constraint('==', $candidate->getVersion())))) {
                    $matches[spl_object_hash($candidate)] = $candidate;
                    if ($candidate instanceof AliasPackage && !isset($matches[spl_object_hash($candidate->getAliasOf())])) {
                        $matches[spl_object_hash($candidate->getAliasOf())] = $candidate->getAliasOf();
                    }
                }
            }
            foreach ($candidates as $candidate) {
                if ($candidate instanceof AliasPackage) {
                    if (isset($result[spl_object_hash($candidate->getAliasOf())])) {
                        $matches[spl_object_hash($candidate)] = $candidate;
                    }
                }
            }
            $packages = array_merge($packages, $matches);
        }

        return $packages;
    }

    /**
     * {@inheritDoc}
     */
    public function search($query, $mode = 0, $type = null)
    {
        $this->loadRootServerFile();

        if ($this->searchUrl && $mode === self::SEARCH_FULLTEXT) {
            $url = str_replace(array('%query%', '%type%'), array($query, $type), $this->searchUrl);

            $search = $this->httpDownloader->get($url, $this->options)->decodeJson();

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

    /**
     * @param string $name package name
     * @param bool $bypassFilters If set to true, this bypasses the stability filtering, and forces a recompute without cache
     * @param callable $isPackageAcceptableCallable
     * @return array|mixed
     */
    public function whatProvides($name, $bypassFilters = false, $isPackageAcceptableCallable = null)
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
            $versionsToLoad = array();
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
                    if (!$bypassFilters && $isPackageAcceptableCallable && !call_user_func($isPackageAcceptableCallable, strtolower($version['name']), VersionParser::parseStability($version['version']))) {
                        continue;
                    }

                    $versionsToLoad[] = $version;
                }
            }

            // load acceptable packages in the providers
            $loadedPackages = $this->createPackages($versionsToLoad, 'Composer\Package\CompletePackage');
            foreach ($loadedPackages as $package) {
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

        foreach ($this->createPackages($repoData, 'Composer\Package\CompletePackage') as $package) {
            $this->addPackage($package);
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

    private function loadAsyncPackages(array $packageNames, $isPackageAcceptableCallable)
    {
        $this->loadRootServerFile();

        $packages = array();
        $repo = $this;

        // TODO what if not, then throw?
        if ($this->lazyProvidersUrl) {
            foreach ($packageNames as $name => $constraint) {
                $name = strtolower($name);

                // skip platform packages, root package and composer-plugin-api
                if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name) || '__root__' === $name || 'composer-plugin-api' === $name) {
                    continue;
                }

                $url = str_replace('%package%', $name, $this->lazyProvidersUrl);
                $cacheKey = 'provider-'.strtr($name, '/', '$').'.json';

                $lastModified = null;
                if ($contents = $this->cache->read($cacheKey)) {
                    $contents = json_decode($contents, true);
                    $lastModified = isset($contents['last-modified']) ? $contents['last-modified'] : null;
                }

                $this->asyncFetchFile($url, $cacheKey, $lastModified)
                    ->then(function ($response) use (&$packages, $contents, $name, $constraint, $repo, $isPackageAcceptableCallable) {
                        static $uniqKeys = array('version', 'version_normalized', 'source', 'dist', 'time');

                        if (true === $response) {
                            $response = $contents;
                        }

                        if (!isset($response['packages'][$name])) {
                            return;
                        }

                        $versionsToLoad = array();
                        foreach ($response['packages'][$name] as $version) {
                            if (isset($version['version_normalizeds'])) {
                                foreach ($version['version_normalizeds'] as $index => $normalizedVersion) {
                                    if (!$repo->isVersionAcceptable($isPackageAcceptableCallable, $constraint, $name, $normalizedVersion)) {
                                        foreach ($uniqKeys as $key) {
                                            unset($version[$key.'s'][$index]);
                                        }
                                    }
                                }
                                if (count($version['version_normalizeds'])) {
                                    $versionsToLoad[] = $version;
                                }
                            } else {
                                if (!isset($version['version_normalized'])) {
                                    $version['version_normalized'] = $repo->versionParser->normalize($version['version']);
                                }

                                if ($repo->isVersionAcceptable($isPackageAcceptableCallable, $constraint, $name, $version['version_normalized'])) {
                                    $versionsToLoad[] = $version;
                                }
                            }
                        }

                        $loadedPackages = $repo->createPackages($versionsToLoad, 'Composer\Package\CompletePackage');
                        foreach ($loadedPackages as $package) {
                            $package->setRepository($repo);

                            $packages[spl_object_hash($package)] = $package;
                            if ($package instanceof AliasPackage && !isset($packages[spl_object_hash($package->getAliasOf())])) {
                                $packages[spl_object_hash($package->getAliasOf())] = $package->getAliasOf();
                            }
                        }
                    }, function ($e) {
                        // TODO use ->done() above instead with react/promise 2.0
                        var_dump('Uncaught Ex', $e->getMessage());
                        throw $e;
                    });
            }
        }

        $this->httpDownloader->wait();

        return $packages;
        // RepositorySet should call loadMetadata, getMetadata when all promises resolved, then metadataComplete when done so we can GC the loaded json and whatnot then as needed
    }

    /**
     * TODO v3 should make this private once we can drop PHP 5.3 support
     *
     * @private
     */
    public function isVersionAcceptable($isPackageAcceptableCallable, $constraint, $name, $versionNormalized)
    {
        if (!call_user_func($isPackageAcceptableCallable, strtolower($name), VersionParser::parseStability($versionNormalized))) {
            return false;
        }

        if ($constraint && !$constraint->matches(new Constraint('==', $versionNormalized))) {
            return false;
        }

        return true;
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

        // metadata-url indiates V2 repo protocol so it takes over from all the V1 types
        // V2 only has lazyProviders and no ability to process anything else, plus support for async loading
        if (!empty($data['metadata-url'])) {
            $this->lazyProvidersUrl = $this->canonicalizeUrl($data['metadata-url']);
            $this->providersUrl = null;
            $this->hasProviders = false;
            $this->hasPartialPackages = false;
            $this->allowSslDowngrade = false;
            unset($data['providers-url'], $data['providers'], $data['providers-includes']);
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

    /**
     * TODO v3 should make this private once we can drop PHP 5.3 support
     *
     * @private
     */
    public function createPackages(array $packages, $class = 'Composer\Package\CompletePackage')
    {
        if (!$packages) {
            return;
        }

        try {
            foreach ($packages as &$data) {
                if (!isset($data['notification-url'])) {
                    $data['notification-url'] = $this->notifyUrl;
                }
            }

            $packages = $this->loader->loadPackages($packages, $class);

            foreach ($packages as $package) {
                if (isset($this->sourceMirrors[$package->getSourceType()])) {
                    $package->setSourceMirrors($this->sourceMirrors[$package->getSourceType()]);
                }
                $package->setDistMirrors($this->distMirrors);
                $this->configurePackageTransportOptions($package);
            }

            return $packages;
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not load packages '.(isset($packages[0]['name']) ? $packages[0]['name'] : json_encode($packages)).' in '.$this->url.': ['.get_class($e).'] '.$e->getMessage(), 0, $e);
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
                $httpDownloader = $this->httpDownloader;

                if ($this->eventDispatcher) {
                    $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename);
                    $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                    $httpDownloader = $preFileDownloadEvent->getHttpDownloader();
                }

                $response = $httpDownloader->get($filename, $this->options);
                $json = $response->getBody();
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

                $data = $response->decodeJson();
                if (!empty($data['warning'])) {
                    $this->io->writeError('<warning>Warning from '.$this->url.': '.$data['warning'].'</warning>');
                }
                if (!empty($data['info'])) {
                    $this->io->writeError('<info>Info from '.$this->url.': '.$data['info'].'</info>');
                }

                if ($cacheKey) {
                    if ($storeLastModifiedTime) {
                        $lastModifiedDate = $response->getHeader('last-modified');
                        if ($lastModifiedDate) {
                            $data['last-modified'] = $lastModifiedDate;
                            $json = json_encode($data);
                        }
                    }
                    $this->cache->write($cacheKey, $json);
                }

                $response->collect();

                break;
            } catch (\Exception $e) {
                if ($e instanceof \LogicException) {
                    throw $e;
                }

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
                $httpDownloader = $this->httpDownloader;

                if ($this->eventDispatcher) {
                    $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename);
                    $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                    $httpDownloader = $preFileDownloadEvent->getHttpDownloader();
                }

                $options = $this->options;
                if (isset($options['http']['header'])) {
                    $options['http']['header'] = (array) $options['http']['header'];
                }
                $options['http']['header'][] = array('If-Modified-Since: '.$lastModifiedTime);
                $response = $httpDownloader->get($filename, $options);
                $json = $response->getBody();
                if ($json === '' && $response->getStatusCode() === 304) {
                    return true;
                }

                $data = $response->decodeJson();
                if (!empty($data['warning'])) {
                    $this->io->writeError('<warning>Warning from '.$this->url.': '.$data['warning'].'</warning>');
                }
                if (!empty($data['info'])) {
                    $this->io->writeError('<info>Info from '.$this->url.': '.$data['info'].'</info>');
                }

                $lastModifiedDate = $response->getHeader('last-modified');
                $response->collect();
                if ($lastModifiedDate) {
                    $data['last-modified'] = $lastModifiedDate;
                    $json = json_encode($data);
                }
                $this->cache->write($cacheKey, $json);

                return $data;
            } catch (\Exception $e) {
                if ($e instanceof \LogicException) {
                    throw $e;
                }

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

    protected function asyncFetchFile($filename, $cacheKey, $lastModifiedTime = null)
    {
        $retries = 3;
        $httpDownloader = $this->httpDownloader;

        if ($this->eventDispatcher) {
            $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename);
            $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
            $httpDownloader = $preFileDownloadEvent->getHttpDownloader();
        }

        $options = $lastModifiedTime ? array('http' => array('header' => array('If-Modified-Since: '.$lastModifiedTime))) : array();

        $io = $this->io;
        $url = $this->url;
        $cache = $this->cache;
        $degradedMode =& $this->degradedMode;

        $accept = function ($response) use ($io, $url, $cache, $cacheKey) {
            // package not found is acceptable for a v2 protocol repository
            if ($response->getStatusCode() === 404) {
                return array('packages' => array());
            }

            $json = $response->getBody();
            if ($json === '' && $response->getStatusCode() === 304) {
                return true;
            }

            $data = $response->decodeJson();
            if (!empty($data['warning'])) {
                $io->writeError('<warning>Warning from '.$url.': '.$data['warning'].'</warning>');
            }
            if (!empty($data['info'])) {
                $io->writeError('<info>Info from '.$url.': '.$data['info'].'</info>');
            }

            $lastModifiedDate = $response->getHeader('last-modified');
            $response->collect();
            if ($lastModifiedDate) {
                $data['last-modified'] = $lastModifiedDate;
                $json = JsonFile::encode($data, JsonFile::JSON_UNESCAPED_SLASHES | JsonFile::JSON_UNESCAPED_UNICODE);
            }
            $cache->write($cacheKey, $json);

            return $data;
        };

        $reject = function ($e) use (&$retries, $httpDownloader, $filename, $options, &$reject, $accept, $io, $url, $cache, &$degradedMode) {
            var_dump('Caught8', $e->getMessage());
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                return false;
            }

            // special error code returned when network is being artificially disabled
            if ($e instanceof TransportException && $e->getStatusCode() === 499) {
                $retries = 0;
            }

            if (--$retries > 0) {
                usleep(100000);

                return $httpDownloader->add($filename, $options)->then($accept, $reject);
            }

            if (!$degradedMode) {
                $io->writeError('<warning>'.$e->getMessage().'</warning>');
                $io->writeError('<warning>'.$url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
            }
            $degradedMode = true;

            return true;
        };

        return $httpDownloader->add($filename, $options)->then($accept, $reject);
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
