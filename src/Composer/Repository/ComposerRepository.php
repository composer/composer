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
use Composer\Util\Loop;
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
    private $config;
    private $repoConfig;
    private $options;
    private $url;
    private $baseUrl;
    private $io;
    private $httpDownloader;
    private $loop;
    protected $cache;
    protected $notifyUrl;
    protected $searchUrl;
    protected $hasProviders = false;
    protected $providersUrl;
    protected $availablePackages;
    protected $lazyProvidersUrl;
    protected $providerListing;
    protected $loader;
    private $allowSslDowngrade = false;
    private $eventDispatcher;
    private $sourceMirrors;
    private $distMirrors;
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
        $this->loop = new Loop($this->httpDownloader);
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
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

        if ($this->lazyProvidersUrl) {
            if ($this->hasPartialPackages() && isset($this->partialPackagesByName[$name])) {
                return $this->filterPackages($this->whatProvides($name), $constraint, true);
            }

            if (is_array($this->availablePackages) && !isset($this->availablePackages[$name])) {
                return;
            }

            $packages = $this->loadAsyncPackages(array($name => $constraint), function ($name, $stability) {
                return true;
            });

            return reset($packages);
        }

        if ($hasProviders) {
            foreach ($this->getProviderNames() as $providerName) {
                if ($name === $providerName) {
                    return $this->filterPackages($this->whatProvides($providerName), $constraint, true);
                }
            }

            return;
        }

        return parent::findPackage($name, $constraint);
    }

    /**
     * {@inheritDoc}
     */
    public function findPackages($name, $constraint = null)
    {
        // this call initializes loadRootServerFile which is needed for the rest below to work
        $hasProviders = $this->hasProviders();

        $name = strtolower($name);
        if (null !== $constraint && !$constraint instanceof ConstraintInterface) {
            $constraint = $this->versionParser->parseConstraints($constraint);
        }

        if ($this->lazyProvidersUrl) {
            if ($this->hasPartialPackages() && isset($this->partialPackagesByName[$name])) {
                return $this->filterPackages($this->whatProvides($name), $constraint);
            }

            if (is_array($this->availablePackages) && !isset($this->availablePackages[$name])) {
                return array();
            }

            return $this->loadAsyncPackages(array($name => $constraint ?: new EmptyConstraint()), function ($name, $stability) {
                return true;
            });
        }

        if ($hasProviders) {
            foreach ($this->getProviderNames() as $providerName) {
                if ($name === $providerName) {
                    return $this->filterPackages($this->whatProvides($providerName), $constraint);
                }
            }

            return array();
        }

        return parent::findPackages($name, $constraint);
    }

    private function filterPackages(array $packages, $constraint = null, $returnFirstMatch = false)
    {
        if (null === $constraint) {
            if ($returnFirstMatch) {
                return reset($packages);
            }

            return $packages;
        }

        $filteredPackages = array();

        foreach ($packages as $package) {
            $pkgConstraint = new Constraint('==', $package->getVersion());

            if ($constraint->matches($pkgConstraint)) {
                if ($returnFirstMatch) {
                    return $package;
                }

                $filteredPackages[] = $package;
            }
        }

        if ($returnFirstMatch) {
            return null;
        }

        return $filteredPackages;
    }

    public function getPackages()
    {
        $hasProviders = $this->hasProviders();

        if ($this->lazyProvidersUrl) {
            if (is_array($this->availablePackages)) {
                $packageMap = array();
                foreach ($this->availablePackages as $name) {
                    $packageMap[$name] = new EmptyConstraint();
                }

                return array_values($this->loadAsyncPackages($packageMap, function ($name, $stability) { return true; }));
            }

            throw new \LogicException('Composer repositories that have lazy providers and no available-packages list can not load the complete list of packages, use getProviderNames instead.');
        }

        if ($hasProviders) {
            throw new \LogicException('Composer repositories that have providers can not load the complete list of packages, use getProviderNames instead.');
        }

        return parent::getPackages();
    }

    public function getPackageNames()
    {
        // TODO add getPackageNames to the RepositoryInterface perhaps? With filtering capability embedded?
        $hasProviders = $this->hasProviders();

        if ($this->lazyProvidersUrl) {
            if (is_array($this->availablePackages)) {
                return array_keys($this->availablePackages);
            }

            // TODO implement new list API endpoint for those repos somehow?
            return array();
        }

        if ($hasProviders) {
            return $this->getProviderNames();
        }

        $names = array();
        foreach ($this->getPackages() as $package) {
            $names[] = $package->getPrettyName();
        }

        return $names;
    }

    public function loadPackages(array $packageNameMap, $isPackageAcceptableCallable)
    {
        // this call initializes loadRootServerFile which is needed for the rest below to work
        $hasProviders = $this->hasProviders();

        if (!$hasProviders && !$this->hasPartialPackages() && !$this->lazyProvidersUrl) {
            return parent::loadPackages($packageNameMap, $isPackageAcceptableCallable);
        }

        $packages = array();

        if ($hasProviders || $this->hasPartialPackages()) {
            foreach ($packageNameMap as $name => $constraint) {
                $matches = array();

                // if a repo has no providers but only partial packages and the partial packages are missing
                // then we don't want to call whatProvides as it would try to load from the providers and fail
                if (!$hasProviders && !isset($this->partialPackagesByName[$name])) {
                    continue;
                }

                $candidates = $this->whatProvides($name, $isPackageAcceptableCallable);
                foreach ($candidates as $candidate) {
                    if ($candidate->getName() !== $name) {
                        throw new \LogicException('whatProvides should never return a package with a different name than the requested one');
                    }
                    if (!$constraint || $constraint->matches(new Constraint('==', $candidate->getVersion()))) {
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

                unset($packageNameMap[$name]);
            }
        }

        if ($this->lazyProvidersUrl && count($packageNameMap)) {
            if (is_array($this->availablePackages)) {
                $availPackages = $this->availablePackages;
                $packageNameMap = array_filter($packageNameMap, function ($name) use ($availPackages) {
                    return isset($availPackages[strtolower($name)]);
                }, ARRAY_FILTER_USE_KEY);
            }

            $packages = array_merge($packages, $this->loadAsyncPackages($packageNameMap, $isPackageAcceptableCallable));
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

        if ($this->hasProviders() || $this->lazyProvidersUrl) {
            $results = array();
            $regex = '{(?:'.implode('|', preg_split('{\s+}', $query)).')}i';

            foreach ($this->getPackageNames() as $name) {
                if (preg_match($regex, $name)) {
                    $results[] = array('name' => $name);
                }
            }

            return $results;
        }

        return parent::search($query, $mode);
    }

    private function getProviderNames()
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

    private function configurePackageTransportOptions(PackageInterface $package)
    {
        foreach ($package->getDistUrls() as $url) {
            if (strpos($url, $this->baseUrl) === 0) {
                $package->setTransportOptions($this->options);

                return;
            }
        }
    }

    private function hasProviders()
    {
        $this->loadRootServerFile();

        return $this->hasProviders;
    }

    /**
     * @param string $name package name
     * @param callable $isPackageAcceptableCallable
     * @return array|mixed
     */
    private function whatProvides($name, $isPackageAcceptableCallable = null)
    {
        if (!$this->hasPartialPackages() || !isset($this->partialPackagesByName[$name])) {
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

        $result = array();
        $versionsToLoad = array();
        foreach ($packages['packages'] as $versions) {
            foreach ($versions as $version) {
                $normalizedName = strtolower($version['name']);

                // only load the actual named package, not other packages that might find themselves in the same file
                if ($normalizedName !== $name) {
                    continue;
                }

                if (!$loadingPartialPackage && $this->hasPartialPackages() && isset($this->partialPackagesByName[$normalizedName])) {
                    continue;
                }

                if (!isset($versionsToLoad[$version['uid']])) {
                    if ($isPackageAcceptableCallable && !call_user_func($isPackageAcceptableCallable, $normalizedName, VersionParser::parseStability($version['version']))) {
                        continue;
                    }

                    $versionsToLoad[$version['uid']] = $version;
                }
            }
        }

        // load acceptable packages in the providers
        $loadedPackages = $this->createPackages($versionsToLoad, 'Composer\Package\CompletePackage');
        $uids = array_keys($versionsToLoad);

        foreach ($loadedPackages as $index => $package) {
            $package->setRepository($this);
            $uid = $uids[$index];

            if ($package instanceof AliasPackage) {
                $aliased = $package->getAliasOf();
                $aliased->setRepository($this);

                $result[$uid] = $aliased;
                $result[$uid.'-alias'] = $package;
            } else {
                $result[$uid] = $package;
            }
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
        $promises = array();
        $repo = $this;

        if (!$this->lazyProvidersUrl) {
            throw new \LogicException('loadAsyncPackages only supports v2 protocol composer repos with a metadata-url');
        }

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

            $promises[] = $this->asyncFetchFile($url, $cacheKey, $lastModified)
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
                });
        }

        $this->loop->wait($promises);

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
        // V2 only has lazyProviders and possibly partial packages, but no ability to process anything else,
        // V2 also supports async loading
        if (!empty($data['metadata-url'])) {
            $this->lazyProvidersUrl = $this->canonicalizeUrl($data['metadata-url']);
            $this->providersUrl = null;
            $this->hasProviders = false;
            $this->hasPartialPackages = !empty($data['packages']) && is_array($data['packages']);
            $this->allowSslDowngrade = false;

            // provides a list of package names that are available in this repo
            // this disables lazy-provider behavior in the sense that if a list is available we assume it is finite and won't search for other packages in that repo
            // while if no list is there lazyProvidersUrl is used when looking for any package name to see if the repo knows it
            if (!empty($data['available-packages'])) {
                $availPackages = array_map('strtolower', $data['available-packages']);
                $this->availablePackages = array_combine($availPackages, $availPackages);
            }

            // Remove legacy keys as most repos need to be compatible with Composer v1
            // as well but we are not interested in the old format anymore at this point
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

    private function canonicalizeUrl($url)
    {
        if ('/' === $url[0]) {
            return preg_replace('{(https?://[^/]+).*}i', '$1' . $url, $this->url);
        }

        return $url;
    }

    private function loadDataFromServer()
    {
        $data = $this->loadRootServerFile();

        return $this->loadIncludes($data);
    }

    private function hasPartialPackages()
    {
        if ($this->hasPartialPackages && null === $this->partialPackagesByName) {
            $this->initializePartialPackages();
        }

        return $this->hasPartialPackages;
    }

    private function loadProviderListings($data)
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

    private function loadIncludes($data)
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
            return array();
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

    private function fetchFileIfLastModified($filename, $cacheKey, $lastModifiedTime)
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

    private function asyncFetchFile($filename, $cacheKey, $lastModifiedTime = null)
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

            throw $e;
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
            foreach ($versions as $version) {
                $this->partialPackagesByName[strtolower($version['name'])][] = $version;
            }
        }

        // wipe rootData as it is fully consumed at this point and this saves some memory
        $this->rootData = true;
    }
}
