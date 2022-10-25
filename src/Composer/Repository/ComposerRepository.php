<?php declare(strict_types=1);

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

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\StabilityFilter;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Semver\CompilingMatcher;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Downloader\TransportException;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Util\Http\Response;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Util\Url;
use React\Promise\PromiseInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository implements ConfigurableRepositoryInterface, AdvisoryProviderInterface
{
    /**
     * @var mixed[]
     * @phpstan-var array{url: string, options?: mixed[], type?: 'composer', allow_ssl_downgrade?: bool}
     */
    private $repoConfig;
    /** @var mixed[] */
    private $options;
    /** @var non-empty-string */
    private $url;
    /** @var non-empty-string */
    private $baseUrl;
    /** @var IOInterface */
    private $io;
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var Loop */
    private $loop;
    /** @var Cache */
    protected $cache;
    /** @var ?non-empty-string */
    protected $notifyUrl = null;
    /** @var ?non-empty-string */
    protected $searchUrl = null;
    /** @var ?non-empty-string a URL containing %package% which can be queried to get providers of a given name */
    protected $providersApiUrl = null;
    /** @var bool */
    protected $hasProviders = false;
    /** @var ?non-empty-string */
    protected $providersUrl = null;
    /** @var ?non-empty-string */
    protected $listUrl = null;
    /** @var bool Indicates whether a comprehensive list of packages this repository might provide is expressed in the repository root. **/
    protected $hasAvailablePackageList = false;
    /** @var ?array<string> */
    protected $availablePackages = null;
    /** @var ?array<non-empty-string> */
    protected $availablePackagePatterns = null;
    /** @var ?non-empty-string */
    protected $lazyProvidersUrl = null;
    /** @var ?array<string, array{sha256: string}> */
    protected $providerListing;
    /** @var ArrayLoader */
    protected $loader;
    /** @var bool */
    private $allowSslDowngrade = false;
    /** @var ?EventDispatcher */
    private $eventDispatcher;
    /** @var ?array<string, array<int, array{url: non-empty-string, preferred: bool}>> */
    private $sourceMirrors;
    /** @var ?array<int, array{url: non-empty-string, preferred: bool}> */
    private $distMirrors;
    /** @var bool */
    private $degradedMode = false;
    /** @var mixed[]|true */
    private $rootData;
    /** @var bool */
    private $hasPartialPackages = false;
    /** @var ?array<string, mixed[]> */
    private $partialPackagesByName = null;
    /** @var bool */
    private $displayedWarningAboutNonMatchingPackageIndex = false;
    /** @var array{metadata: bool, query-all: bool, api-url: string|null}|null */
    private $securityAdvisoryConfig = null;

    /**
     * @var array list of package names which are fresh and can be loaded from the cache directly in case loadPackage is called several times
     *            useful for v2 metadata repositories with lazy providers
     * @phpstan-var array<string, true>
     */
    private $freshMetadataUrls = [];

    /**
     * @var array list of package names which returned a 404 and should not be re-fetched in case loadPackage is called several times
     *            useful for v2 metadata repositories with lazy providers
     * @phpstan-var array<string, true>
     */
    private $packagesNotFoundCache = [];

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @param array<string, mixed> $repoConfig
     * @phpstan-param array{url: non-empty-string, options?: mixed[], type?: 'composer', allow_ssl_downgrade?: bool} $repoConfig
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, ?EventDispatcher $eventDispatcher = null)
    {
        parent::__construct();
        if (!Preg::isMatch('{^[\w.]+\??://}', $repoConfig['url'])) {
            // assume http as the default protocol
            $repoConfig['url'] = 'http://'.$repoConfig['url'];
        }
        $repoConfig['url'] = rtrim($repoConfig['url'], '/');
        if ($repoConfig['url'] === '') {
            throw new \InvalidArgumentException('The repository url must not be an empty string');
        }

        if (str_starts_with($repoConfig['url'], 'https?')) {
            $repoConfig['url'] = (extension_loaded('openssl') ? 'https' : 'http') . substr($repoConfig['url'], 6);
        }

        $urlBits = parse_url(strtr($repoConfig['url'], '\\', '/'));
        if ($urlBits === false || empty($urlBits['scheme'])) {
            throw new \UnexpectedValueException('Invalid url given for Composer repository: '.$repoConfig['url']);
        }

        if (!isset($repoConfig['options'])) {
            $repoConfig['options'] = [];
        }
        if (isset($repoConfig['allow_ssl_downgrade']) && true === $repoConfig['allow_ssl_downgrade']) {
            $this->allowSslDowngrade = true;
        }

        $this->options = $repoConfig['options'];
        $this->url = $repoConfig['url'];

        // force url for packagist.org to repo.packagist.org
        if (Preg::isMatch('{^(?P<proto>https?)://packagist\.org/?$}i', $this->url, $match)) {
            $this->url = $match['proto'].'://repo.packagist.org';
        }

        $baseUrl = rtrim(Preg::replace('{(?:/[^/\\\\]+\.json)?(?:[?#].*)?$}', '', $this->url), '/');
        assert($baseUrl !== '');
        $this->baseUrl = $baseUrl;
        $this->io = $io;
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.Preg::replace('{[^a-z0-9.]}i', '-', Url::sanitize($this->url)), 'a-z0-9.$~');
        $this->cache->setReadOnly($config->get('cache-read-only'));
        $this->versionParser = new VersionParser();
        $this->loader = new ArrayLoader($this->versionParser);
        $this->httpDownloader = $httpDownloader;
        $this->eventDispatcher = $eventDispatcher;
        $this->repoConfig = $repoConfig;
        $this->loop = new Loop($this->httpDownloader);
    }

    public function getRepoName()
    {
        return 'composer repo ('.Url::sanitize($this->url).')';
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    /**
     * @inheritDoc
     */
    public function findPackage(string $name, $constraint)
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

            if ($this->hasAvailablePackageList && !$this->lazyProvidersRepoContains($name)) {
                return null;
            }

            $packages = $this->loadAsyncPackages([$name => $constraint]);

            if (count($packages['packages']) > 0) {
                return reset($packages['packages']);
            }

            return null;
        }

        if ($hasProviders) {
            foreach ($this->getProviderNames() as $providerName) {
                if ($name === $providerName) {
                    return $this->filterPackages($this->whatProvides($providerName), $constraint, true);
                }
            }

            return null;
        }

        return parent::findPackage($name, $constraint);
    }

    /**
     * @inheritDoc
     */
    public function findPackages(string $name, $constraint = null)
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

            if ($this->hasAvailablePackageList && !$this->lazyProvidersRepoContains($name)) {
                return [];
            }

            $result = $this->loadAsyncPackages([$name => $constraint]);

            return $result['packages'];
        }

        if ($hasProviders) {
            foreach ($this->getProviderNames() as $providerName) {
                if ($name === $providerName) {
                    return $this->filterPackages($this->whatProvides($providerName), $constraint);
                }
            }

            return [];
        }

        return parent::findPackages($name, $constraint);
    }

    /**
     * @param array<BasePackage> $packages
     *
     * @return BasePackage|array<BasePackage>|null
     */
    private function filterPackages(array $packages, ?ConstraintInterface $constraint = null, bool $returnFirstMatch = false)
    {
        if (null === $constraint) {
            if ($returnFirstMatch) {
                return reset($packages);
            }

            return $packages;
        }

        $filteredPackages = [];

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
            if (is_array($this->availablePackages) && !$this->availablePackagePatterns) {
                $packageMap = [];
                foreach ($this->availablePackages as $name) {
                    $packageMap[$name] = new MatchAllConstraint();
                }

                $result = $this->loadAsyncPackages($packageMap);

                return array_values($result['packages']);
            }

            if ($this->hasPartialPackages()) {
                if (!is_array($this->partialPackagesByName)) {
                    throw new \LogicException('hasPartialPackages failed to initialize $this->partialPackagesByName');
                }

                return $this->createPackages($this->partialPackagesByName, 'packages.json inline packages');
            }

            throw new \LogicException('Composer repositories that have lazy providers and no available-packages list can not load the complete list of packages, use getPackageNames instead.');
        }

        if ($hasProviders) {
            throw new \LogicException('Composer repositories that have providers can not load the complete list of packages, use getPackageNames instead.');
        }

        return parent::getPackages();
    }

    /**
     * @param string|null $packageFilter Package pattern filter which can include "*" as a wildcard
     *
     * @return string[]
     */
    public function getPackageNames(?string $packageFilter = null)
    {
        $hasProviders = $this->hasProviders();

        $filterResults =
            /**
             * @param list<string> $results
             * @return list<string>
             */
            static function (array $results): array {
                return $results;
            }
        ;
        if (null !== $packageFilter && '' !== $packageFilter) {
            $packageFilterRegex = BasePackage::packageNameToRegexp($packageFilter);
            $filterResults =
                /**
                 * @param list<string> $results
                 * @return list<string>
                 */
                static function (array $results) use ($packageFilterRegex): array {
                    /** @var list<string> $results */
                    return Preg::grep($packageFilterRegex, $results);
                }
            ;
        }

        if ($this->lazyProvidersUrl) {
            if (is_array($this->availablePackages)) {
                return $filterResults(array_keys($this->availablePackages));
            }

            if ($this->listUrl) {
                // no need to call $filterResults here as the $packageFilter is applied in the function itself
                return $this->loadPackageList($packageFilter);
            }

            if ($this->hasPartialPackages() && $this->partialPackagesByName !== null) {
                return $filterResults(array_keys($this->partialPackagesByName));
            }

            return [];
        }

        if ($hasProviders) {
            return $filterResults($this->getProviderNames());
        }

        $names = [];
        foreach ($this->getPackages() as $package) {
            $names[] = $package->getPrettyName();
        }

        return $filterResults($names);
    }

    /**
     * @return list<string>
     */
    private function getVendorNames(): array
    {
        $cacheKey = 'vendor-list.txt';
        $cacheAge = $this->cache->getAge($cacheKey);
        if (false !== $cacheAge && $cacheAge < 600 && ($cachedData = $this->cache->read($cacheKey)) !== false) {
            $cachedData = explode("\n", $cachedData);

            return $cachedData;
        }

        $names = $this->getPackageNames();

        $uniques = [];
        foreach ($names as $name) {
            // @phpstan-ignore-next-line
            $uniques[substr($name, 0, strpos($name, '/'))] = true;
        }

        $vendors = array_keys($uniques);

        if (!$this->cache->isReadOnly()) {
            $this->cache->write($cacheKey, implode("\n", $vendors));
        }

        return $vendors;
    }

    /**
     * @return list<string>
     */
    private function loadPackageList(?string $packageFilter = null): array
    {
        if (null === $this->listUrl) {
            throw new \LogicException('Make sure to call loadRootServerFile before loadPackageList');
        }

        $url = $this->listUrl;
        if (is_string($packageFilter) && $packageFilter !== '') {
            $url .= '?filter='.urlencode($packageFilter);
            $result = $this->httpDownloader->get($url, $this->options)->decodeJson();

            return $result['packageNames'];
        }

        $cacheKey = 'package-list.txt';
        $cacheAge = $this->cache->getAge($cacheKey);
        if (false !== $cacheAge && $cacheAge < 600 && ($cachedData = $this->cache->read($cacheKey)) !== false) {
            $cachedData = explode("\n", $cachedData);

            return $cachedData;
        }

        $result = $this->httpDownloader->get($url, $this->options)->decodeJson();
        if (!$this->cache->isReadOnly()) {
            $this->cache->write($cacheKey, implode("\n", $result['packageNames']));
        }

        return $result['packageNames'];
    }

    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = [])
    {
        // this call initializes loadRootServerFile which is needed for the rest below to work
        $hasProviders = $this->hasProviders();

        if (!$hasProviders && !$this->hasPartialPackages() && null === $this->lazyProvidersUrl) {
            return parent::loadPackages($packageNameMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
        }

        $packages = [];
        $namesFound = [];

        if ($hasProviders || $this->hasPartialPackages()) {
            foreach ($packageNameMap as $name => $constraint) {
                $matches = [];

                // if a repo has no providers but only partial packages and the partial packages are missing
                // then we don't want to call whatProvides as it would try to load from the providers and fail
                if (!$hasProviders && !isset($this->partialPackagesByName[$name])) {
                    continue;
                }

                $candidates = $this->whatProvides($name, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
                foreach ($candidates as $candidate) {
                    if ($candidate->getName() !== $name) {
                        throw new \LogicException('whatProvides should never return a package with a different name than the requested one');
                    }
                    $namesFound[$name] = true;

                    if (!$constraint || $constraint->matches(new Constraint('==', $candidate->getVersion()))) {
                        $matches[spl_object_hash($candidate)] = $candidate;
                        if ($candidate instanceof AliasPackage && !isset($matches[spl_object_hash($candidate->getAliasOf())])) {
                            $matches[spl_object_hash($candidate->getAliasOf())] = $candidate->getAliasOf();
                        }
                    }
                }

                // add aliases of matched packages even if they did not match the constraint
                foreach ($candidates as $candidate) {
                    if ($candidate instanceof AliasPackage) {
                        if (isset($matches[spl_object_hash($candidate->getAliasOf())])) {
                            $matches[spl_object_hash($candidate)] = $candidate;
                        }
                    }
                }
                $packages = array_merge($packages, $matches);

                unset($packageNameMap[$name]);
            }
        }

        if ($this->lazyProvidersUrl && count($packageNameMap)) {
            if ($this->hasAvailablePackageList) {
                foreach ($packageNameMap as $name => $constraint) {
                    if (!$this->lazyProvidersRepoContains(strtolower($name))) {
                        unset($packageNameMap[$name]);
                    }
                }
            }

            $result = $this->loadAsyncPackages($packageNameMap, $acceptableStabilities, $stabilityFlags, $alreadyLoaded);
            $packages = array_merge($packages, $result['packages']);
            $namesFound = array_merge($namesFound, $result['namesFound']);
        }

        return ['namesFound' => array_keys($namesFound), 'packages' => $packages];
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, int $mode = 0, ?string $type = null)
    {
        $this->loadRootServerFile(600);

        if ($this->searchUrl && $mode === self::SEARCH_FULLTEXT) {
            $url = str_replace(['%query%', '%type%'], [urlencode($query), $type], $this->searchUrl);

            $search = $this->httpDownloader->get($url, $this->options)->decodeJson();

            if (empty($search['results'])) {
                return [];
            }

            $results = [];
            foreach ($search['results'] as $result) {
                // do not show virtual packages in results as they are not directly useful from a composer perspective
                if (!empty($result['virtual'])) {
                    continue;
                }

                $results[] = $result;
            }

            return $results;
        }

        if ($mode === self::SEARCH_VENDOR) {
            $results = [];
            $regex = '{(?:'.implode('|', Preg::split('{\s+}', $query)).')}i';

            $vendorNames = $this->getVendorNames();
            foreach (Preg::grep($regex, $vendorNames) as $name) {
                $results[] = ['name' => $name, 'description' => ''];
            }

            return $results;
        }

        if ($this->hasProviders() || $this->lazyProvidersUrl) {
            // optimize search for "^foo/bar" where at least "^foo/" is present by loading this directly from the listUrl if present
            if (Preg::isMatch('{^\^(?P<query>(?P<vendor>[a-z0-9_.-]+)/[a-z0-9_.-]*)\*?$}i', $query, $match) && $this->listUrl !== null) {
                $url = $this->listUrl . '?vendor='.urlencode($match['vendor']).'&filter='.urlencode($match['query'].'*');
                $result = $this->httpDownloader->get($url, $this->options)->decodeJson();

                $results = [];
                foreach ($result['packageNames'] as $name) {
                    $results[] = ['name' => $name, 'description' => ''];
                }

                return $results;
            }

            $results = [];
            $regex = '{(?:'.implode('|', Preg::split('{\s+}', $query)).')}i';

            $packageNames = $this->getPackageNames();
            foreach (Preg::grep($regex, $packageNames) as $name) {
                $results[] = ['name' => $name, 'description' => ''];
            }

            return $results;
        }

        return parent::search($query, $mode);
    }

    public function hasSecurityAdvisories(): bool
    {
        $this->loadRootServerFile(600);

        return $this->securityAdvisoryConfig !== null && ($this->securityAdvisoryConfig['metadata'] || $this->securityAdvisoryConfig['api-url'] !== null);
    }

    /**
     * @inheritDoc
     */
    public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
    {
        $this->loadRootServerFile(600);
        if (null === $this->securityAdvisoryConfig) {
            return ['namesFound' => [], 'advisories' => []];
        }

        $advisories = [];
        $namesFound = [];

        $apiUrl = $this->securityAdvisoryConfig['api-url'];

        $parser = new VersionParser();
        /**
         * @param array<mixed> $data
         * @param string $name
         * @return ($allowPartialAdvisories is false ? SecurityAdvisory|null : PartialSecurityAdvisory|SecurityAdvisory|null)
         */
        $create = function (array $data, string $name) use ($parser, $allowPartialAdvisories, &$packageConstraintMap): ?PartialSecurityAdvisory {
            $advisory = PartialSecurityAdvisory::create($name, $data, $parser);
            if (!$allowPartialAdvisories && !$advisory instanceof SecurityAdvisory) {
                throw new \RuntimeException('Advisory for '.$name.' could not be loaded as a full advisory from '.$this->getRepoName() . PHP_EOL . var_export($data, true));
            }
            if (!$advisory->affectedVersions->matches($packageConstraintMap[$name])) {
                return null;
            }

            return $advisory;
        };

        if ($this->securityAdvisoryConfig['metadata'] && ($allowPartialAdvisories || $apiUrl === null)) {
            $promises = [];
            foreach ($packageConstraintMap as $name => $constraint) {
                $name = strtolower($name);

                // skip platform packages, root package and composer-plugin-api
                if (PlatformRepository::isPlatformPackage($name) || '__root__' === $name) {
                    continue;
                }

                $promises[] = $this->startCachedAsyncDownload($name, $name)
                    ->then(static function (array $spec) use (&$advisories, &$namesFound, &$packageConstraintMap, $name, $create): void {
                        [$response, ] = $spec;

                        if (!isset($response['security-advisories']) || !is_array($response['security-advisories'])) {
                            return;
                        }

                        $namesFound[$name] = true;
                        if (count($response['security-advisories']) > 0) {
                            $advisories[$name] = array_filter(array_map(
                                static function ($data) use ($name, $create) {
                                    return $create($data, $name);
                                },
                                $response['security-advisories']
                            ));
                        }
                        unset($packageConstraintMap[$name]);
                    });
            }

            $this->loop->wait($promises);
        }

        if ($apiUrl !== null && count($packageConstraintMap) > 0) {
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => ['Content-type: application/x-www-form-urlencoded'],
                    'timeout' => 10,
                    'content' => http_build_query(['packages' => array_keys($packageConstraintMap)]),
                ],
            ];
            $response = $this->httpDownloader->get($apiUrl, $options);
            /** @var string $name */
            foreach ($response->decodeJson()['advisories'] as $name => $list) {
                if (count($list) > 0) {
                    $advisories[$name] = array_filter(array_map(
                        static function ($data) use ($name, $create) {
                            return $create($data, $name);
                        },
                        $list
                    ));
                }
                $namesFound[$name] = true;
            }
        }

        return ['namesFound' => array_keys($namesFound), 'advisories' => array_filter($advisories)];
    }

    public function getProviders(string $packageName)
    {
        $this->loadRootServerFile();
        $result = [];

        if ($this->providersApiUrl) {
            try {
                $apiResult = $this->httpDownloader->get(str_replace('%package%', $packageName, $this->providersApiUrl), $this->options)->decodeJson();
            } catch (TransportException $e) {
                if ($e->getStatusCode() === 404) {
                    return $result;
                }
                throw $e;
            }

            foreach ($apiResult['providers'] as $provider) {
                $result[$provider['name']] = $provider;
            }

            return $result;
        }

        if ($this->hasPartialPackages()) {
            if (!is_array($this->partialPackagesByName)) {
                throw new \LogicException('hasPartialPackages failed to initialize $this->partialPackagesByName');
            }
            foreach ($this->partialPackagesByName as $versions) {
                foreach ($versions as $candidate) {
                    if (isset($result[$candidate['name']]) || !isset($candidate['provide'][$packageName])) {
                        continue;
                    }
                    $result[$candidate['name']] = [
                        'name' => $candidate['name'],
                        'description' => $candidate['description'] ?? '',
                        'type' => $candidate['type'] ?? '',
                    ];
                }
            }
        }

        if ($this->packages) {
            $result = array_merge($result, parent::getProviders($packageName));
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function getProviderNames(): array
    {
        $this->loadRootServerFile();

        if (null === $this->providerListing) {
            $data = $this->loadRootServerFile();
            if (is_array($data)) {
                $this->loadProviderListings($data);
            }
        }

        if ($this->lazyProvidersUrl) {
            // Can not determine list of provided packages for lazy repositories
            return [];
        }

        if (null !== $this->providersUrl && null !== $this->providerListing) {
            return array_keys($this->providerListing);
        }

        return [];
    }

    protected function configurePackageTransportOptions(PackageInterface $package): void
    {
        foreach ($package->getDistUrls() as $url) {
            if (strpos($url, $this->baseUrl) === 0) {
                $package->setTransportOptions($this->options);

                return;
            }
        }
    }

    private function hasProviders(): bool
    {
        $this->loadRootServerFile();

        return $this->hasProviders;
    }

    /**
     * @param  string      $name package name
     * @param array<string, int>|null $acceptableStabilities
     * @phpstan-param array<string, BasePackage::STABILITY_*>|null $acceptableStabilities
     * @param array<string, int>|null $stabilityFlags an array of package name => BasePackage::STABILITY_* value
     * @phpstan-param array<string, BasePackage::STABILITY_*>|null $stabilityFlags
     * @param array<string, array<string, PackageInterface>> $alreadyLoaded
     *
     * @return array<string, BasePackage>
     */
    private function whatProvides(string $name, ?array $acceptableStabilities = null, ?array $stabilityFlags = null, array $alreadyLoaded = []): array
    {
        $packagesSource = null;
        if (!$this->hasPartialPackages() || !isset($this->partialPackagesByName[$name])) {
            // skip platform packages, root package and composer-plugin-api
            if (PlatformRepository::isPlatformPackage($name) || '__root__' === $name) {
                return [];
            }

            if (null === $this->providerListing) {
                $data = $this->loadRootServerFile();
                if (is_array($data)) {
                    $this->loadProviderListings($data);
                }
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
                    return [];
                }

                $hash = $this->providerListing[$name]['sha256'];
                $url = str_replace(['%package%', '%hash%'], [$name, $hash], $this->providersUrl);
                $cacheKey = 'provider-'.strtr($name, '/', '$').'.json';
            } else {
                return [];
            }

            $packages = null;
            if (!$useLastModifiedCheck && $hash && $this->cache->sha256($cacheKey) === $hash) {
                $packages = json_decode($this->cache->read($cacheKey), true);
                $packagesSource = 'cached file ('.$cacheKey.' originating from '.Url::sanitize($url).')';
            } elseif ($useLastModifiedCheck) {
                if ($contents = $this->cache->read($cacheKey)) {
                    $contents = json_decode($contents, true);
                    // we already loaded some packages from this file, so assume it is fresh and avoid fetching it again
                    if (isset($alreadyLoaded[$name])) {
                        $packages = $contents;
                        $packagesSource = 'cached file ('.$cacheKey.' originating from '.Url::sanitize($url).')';
                    } elseif (isset($contents['last-modified'])) {
                        $response = $this->fetchFileIfLastModified($url, $cacheKey, $contents['last-modified']);
                        $packages = true === $response ? $contents : $response;
                        $packagesSource = true === $response ? 'cached file ('.$cacheKey.' originating from '.Url::sanitize($url).')' : 'downloaded file ('.Url::sanitize($url).')';
                    }
                }
            }

            if (!$packages) {
                try {
                    $packages = $this->fetchFile($url, $cacheKey, $hash, $useLastModifiedCheck);
                    $packagesSource = 'downloaded file ('.Url::sanitize($url).')';
                } catch (TransportException $e) {
                    // 404s are acceptable for lazy provider repos
                    if ($this->lazyProvidersUrl && in_array($e->getStatusCode(), [404, 499], true)) {
                        $packages = ['packages' => []];
                        $packagesSource = 'not-found file ('.Url::sanitize($url).')';
                        if ($e->getStatusCode() === 499) {
                            $this->io->error('<warning>' . $e->getMessage() . '</warning>');
                        }
                    } else {
                        throw $e;
                    }
                }
            }

            $loadingPartialPackage = false;
        } else {
            $packages = ['packages' => ['versions' => $this->partialPackagesByName[$name]]];
            $packagesSource = 'root file ('.Url::sanitize($this->getPackagesJsonUrl()).')';
            $loadingPartialPackage = true;
        }

        $result = [];
        $versionsToLoad = [];
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
                    if (!isset($version['version_normalized'])) {
                        $version['version_normalized'] = $this->versionParser->normalize($version['version']);
                    } elseif ($version['version_normalized'] === VersionParser::DEFAULT_BRANCH_ALIAS) {
                        // handling of existing repos which need to remain composer v1 compatible, in case the version_normalized contained VersionParser::DEFAULT_BRANCH_ALIAS, we renormalize it
                        $version['version_normalized'] = $this->versionParser->normalize($version['version']);
                    }

                    // avoid loading packages which have already been loaded
                    if (isset($alreadyLoaded[$name][$version['version_normalized']])) {
                        continue;
                    }

                    if ($this->isVersionAcceptable(null, $normalizedName, $version, $acceptableStabilities, $stabilityFlags)) {
                        $versionsToLoad[$version['uid']] = $version;
                    }
                }
            }
        }

        // load acceptable packages in the providers
        $loadedPackages = $this->createPackages($versionsToLoad, $packagesSource);
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
     * @inheritDoc
     */
    protected function initialize()
    {
        parent::initialize();

        $repoData = $this->loadDataFromServer();

        foreach ($this->createPackages($repoData, 'root file ('.Url::sanitize($this->getPackagesJsonUrl()).')') as $package) {
            $this->addPackage($package);
        }
    }

    /**
     * Adds a new package to the repository
     */
    public function addPackage(PackageInterface $package)
    {
        parent::addPackage($package);
        $this->configurePackageTransportOptions($package);
    }

    /**
     * @param array<string, ConstraintInterface|null> $packageNames array of package name => ConstraintInterface|null - if a constraint is provided, only
     *                                                packages matching it will be loaded
     * @param array<string, int>|null $acceptableStabilities
     * @phpstan-param array<string, BasePackage::STABILITY_*>|null $acceptableStabilities
     * @param array<string, int>|null $stabilityFlags an array of package name => BasePackage::STABILITY_* value
     * @phpstan-param array<string, BasePackage::STABILITY_*>|null $stabilityFlags
     * @param array<string, array<string, PackageInterface>> $alreadyLoaded
     *
     * @return array{namesFound: array<string, true>, packages: array<string, BasePackage>}
     */
    private function loadAsyncPackages(array $packageNames, ?array $acceptableStabilities = null, ?array $stabilityFlags = null, array $alreadyLoaded = []): array
    {
        $this->loadRootServerFile();

        $packages = [];
        $namesFound = [];
        $promises = [];

        if (null === $this->lazyProvidersUrl) {
            throw new \LogicException('loadAsyncPackages only supports v2 protocol composer repos with a metadata-url');
        }

        // load ~dev versions of the packages as well if needed
        foreach ($packageNames as $name => $constraint) {
            if ($acceptableStabilities === null || $stabilityFlags === null || StabilityFilter::isPackageAcceptable($acceptableStabilities, $stabilityFlags, [$name], 'dev')) {
                $packageNames[$name.'~dev'] = $constraint;
            }
            // if only dev stability is requested, we skip loading the non dev file
            if (isset($acceptableStabilities['dev']) && count($acceptableStabilities) === 1 && count($stabilityFlags) === 0) {
                unset($packageNames[$name]);
            }
        }

        foreach ($packageNames as $name => $constraint) {
            $name = strtolower($name);

            $realName = Preg::replace('{~dev$}', '', $name);
            // skip platform packages, root package and composer-plugin-api
            if (PlatformRepository::isPlatformPackage($realName) || '__root__' === $realName) {
                continue;
            }

            $promises[] = $this->startCachedAsyncDownload($name, $realName)
                ->then(function (array $spec) use (&$packages, &$namesFound, $realName, $constraint, $acceptableStabilities, $stabilityFlags, $alreadyLoaded): void {
                    [$response, $packagesSource] = $spec;
                    if (null === $response) {
                        return;
                    }

                    $versions = $response['packages'][$realName];

                    if (isset($response['minified']) && $response['minified'] === 'composer/2.0') {
                        $versions = MetadataMinifier::expand($versions);
                    }

                    $namesFound[$realName] = true;
                    $versionsToLoad = [];
                    foreach ($versions as $version) {
                        if (!isset($version['version_normalized'])) {
                            $version['version_normalized'] = $this->versionParser->normalize($version['version']);
                        } elseif ($version['version_normalized'] === VersionParser::DEFAULT_BRANCH_ALIAS) {
                            // handling of existing repos which need to remain composer v1 compatible, in case the version_normalized contained VersionParser::DEFAULT_BRANCH_ALIAS, we renormalize it
                            $version['version_normalized'] = $this->versionParser->normalize($version['version']);
                        }

                        // avoid loading packages which have already been loaded
                        if (isset($alreadyLoaded[$realName][$version['version_normalized']])) {
                            continue;
                        }

                        if ($this->isVersionAcceptable($constraint, $realName, $version, $acceptableStabilities, $stabilityFlags)) {
                            $versionsToLoad[] = $version;
                        }
                    }

                    $loadedPackages = $this->createPackages($versionsToLoad, $packagesSource);
                    foreach ($loadedPackages as $package) {
                        $package->setRepository($this);
                        $packages[spl_object_hash($package)] = $package;

                        if ($package instanceof AliasPackage && !isset($packages[spl_object_hash($package->getAliasOf())])) {
                            $package->getAliasOf()->setRepository($this);
                            $packages[spl_object_hash($package->getAliasOf())] = $package->getAliasOf();
                        }
                    }
                });
        }

        $this->loop->wait($promises);

        return ['namesFound' => $namesFound, 'packages' => $packages];
    }

    private function startCachedAsyncDownload(string $fileName, ?string $packageName = null): PromiseInterface
    {
        if (null === $this->lazyProvidersUrl) {
            throw new \LogicException('startCachedAsyncDownload only supports v2 protocol composer repos with a metadata-url');
        }

        $name = strtolower($fileName);
        $packageName = $packageName ?? $name;

        $url = str_replace('%package%', $name, $this->lazyProvidersUrl);
        $cacheKey = 'provider-'.strtr($name, '/', '~').'.json';

        $lastModified = null;
        if ($contents = $this->cache->read($cacheKey)) {
            $contents = json_decode($contents, true);
            $lastModified = $contents['last-modified'] ?? null;
        }

        return $this->asyncFetchFile($url, $cacheKey, $lastModified)
            ->then(static function ($response) use ($url, $cacheKey, $contents, $packageName): array {
                $packagesSource = 'downloaded file ('.Url::sanitize($url).')';

                if (true === $response) {
                    $packagesSource = 'cached file ('.$cacheKey.' originating from '.Url::sanitize($url).')';
                    $response = $contents;
                }

                if (!isset($response['packages'][$packageName])) {
                    return [null, $packagesSource];
                }

                return [$response, $packagesSource];
            });
    }

    /**
     * @param string $name package name (must be lowercased already)
     * @param array<string, mixed> $versionData
     * @param array<string, int>|null $acceptableStabilities
     * @phpstan-param array<string, BasePackage::STABILITY_*>|null $acceptableStabilities
     * @param array<string, int>|null $stabilityFlags an array of package name => BasePackage::STABILITY_* value
     * @phpstan-param array<string, BasePackage::STABILITY_*>|null $stabilityFlags
     */
    private function isVersionAcceptable(?ConstraintInterface $constraint, string $name, array $versionData, ?array $acceptableStabilities = null, ?array $stabilityFlags = null): bool
    {
        $versions = [$versionData['version_normalized']];

        if ($alias = $this->loader->getBranchAlias($versionData)) {
            $versions[] = $alias;
        }

        foreach ($versions as $version) {
            if (null !== $acceptableStabilities && null !== $stabilityFlags && !StabilityFilter::isPackageAcceptable($acceptableStabilities, $stabilityFlags, [$name], VersionParser::parseStability($version))) {
                continue;
            }

            if ($constraint && !CompilingMatcher::match($constraint, Constraint::OP_EQ, $version)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function getPackagesJsonUrl(): string
    {
        $jsonUrlParts = parse_url(strtr($this->url, '\\', '/'));

        if (isset($jsonUrlParts['path']) && false !== strpos($jsonUrlParts['path'], '.json')) {
            return $this->url;
        }

        return $this->url . '/packages.json';
    }

    /**
     * @return array<'providers'|'provider-includes'|'packages'|'providers-url'|'notify-batch'|'search'|'mirrors'|'providers-lazy-url'|'metadata-url'|'available-packages'|'available-package-patterns', mixed>|true
     */
    protected function loadRootServerFile(?int $rootMaxAge = null)
    {
        if (null !== $this->rootData) {
            return $this->rootData;
        }

        if (!extension_loaded('openssl') && strpos($this->url, 'https') === 0) {
            throw new \RuntimeException('You must enable the openssl extension in your php.ini to load information from '.$this->url);
        }

        if ($cachedData = $this->cache->read('packages.json')) {
            $cachedData = json_decode($cachedData, true);
            if ($rootMaxAge !== null && ($age = $this->cache->getAge('packages.json')) !== false && $age <= $rootMaxAge) {
                $data = $cachedData;
            } elseif (isset($cachedData['last-modified'])) {
                $response = $this->fetchFileIfLastModified($this->getPackagesJsonUrl(), 'packages.json', $cachedData['last-modified']);
                $data = true === $response ? $cachedData : $response;
            }
        }

        if (!isset($data)) {
            $data = $this->fetchFile($this->getPackagesJsonUrl(), 'packages.json', null, true);
        }

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
                    $this->sourceMirrors['git'][] = ['url' => $mirror['git-url'], 'preferred' => !empty($mirror['preferred'])];
                }
                if (!empty($mirror['hg-url'])) {
                    $this->sourceMirrors['hg'][] = ['url' => $mirror['hg-url'], 'preferred' => !empty($mirror['preferred'])];
                }
                if (!empty($mirror['dist-url'])) {
                    $this->distMirrors[] = [
                        'url' => $this->canonicalizeUrl($mirror['dist-url']),
                        'preferred' => !empty($mirror['preferred']),
                    ];
                }
            }
        }

        if (!empty($data['providers-lazy-url'])) {
            $this->lazyProvidersUrl = $this->canonicalizeUrl($data['providers-lazy-url']);
            $this->hasProviders = true;

            $this->hasPartialPackages = !empty($data['packages']) && is_array($data['packages']);
        }

        // metadata-url indicates V2 repo protocol so it takes over from all the V1 types
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
                $this->hasAvailablePackageList = true;
            }

            // Provides a list of package name patterns (using * wildcards to match any substring, e.g. "vendor/*") that are available in this repo
            // Disables lazy-provider behavior as with available-packages, but may allow much more compact expression of packages covered by this repository.
            // Over-specifying covered packages is safe, but may result in increased traffic to your repository.
            if (!empty($data['available-package-patterns'])) {
                $this->availablePackagePatterns = array_map(static function ($pattern): string {
                    return BasePackage::packageNameToRegexp($pattern);
                }, $data['available-package-patterns']);
                $this->hasAvailablePackageList = true;
            }

            // Remove legacy keys as most repos need to be compatible with Composer v1
            // as well but we are not interested in the old format anymore at this point
            unset($data['providers-url'], $data['providers'], $data['providers-includes']);

            if (isset($data['security-advisories']) && is_array($data['security-advisories'])) {
                $this->securityAdvisoryConfig = [
                    'metadata' => $data['security-advisories']['metadata'] ?? false,
                    'api-url' => $data['security-advisories']['api-url'] ?? null,
                    'query-all' => $data['security-advisories']['query-all'] ?? false,
                ];
            }
        }

        if ($this->allowSslDowngrade) {
            $this->url = str_replace('https://', 'http://', $this->url);
            $this->baseUrl = str_replace('https://', 'http://', $this->baseUrl);
        }

        if (!empty($data['providers-url'])) {
            $this->providersUrl = $this->canonicalizeUrl($data['providers-url']);
            $this->hasProviders = true;
        }

        if (!empty($data['list'])) {
            $this->listUrl = $this->canonicalizeUrl($data['list']);
        }

        if (!empty($data['providers']) || !empty($data['providers-includes'])) {
            $this->hasProviders = true;
        }

        if (!empty($data['providers-api'])) {
            $this->providersApiUrl = $this->canonicalizeUrl($data['providers-api']);
        }

        return $this->rootData = $data;
    }

    /**
     * @param non-empty-string $url
     * @return non-empty-string
     */
    private function canonicalizeUrl(string $url): string
    {
        if ('/' === $url[0]) {
            if (Preg::isMatch('{^[^:]++://[^/]*+}', $this->url, $matches)) {
                return $matches[0] . $url;
            }

            return $this->url;
        }

        return $url;
    }

    /**
     * @return mixed[]
     */
    private function loadDataFromServer(): array
    {
        $data = $this->loadRootServerFile();
        if (true === $data) {
            throw new \LogicException('loadRootServerFile should not return true during initialization');
        }

        return $this->loadIncludes($data);
    }

    private function hasPartialPackages(): bool
    {
        if ($this->hasPartialPackages && null === $this->partialPackagesByName) {
            $this->initializePartialPackages();
        }

        return $this->hasPartialPackages;
    }

    /**
     * @param array{providers?: mixed[], provider-includes?: mixed[]} $data
     */
    private function loadProviderListings($data): void
    {
        if (isset($data['providers'])) {
            if (!is_array($this->providerListing)) {
                $this->providerListing = [];
            }
            $this->providerListing = array_merge($this->providerListing, $data['providers']);
        }

        if ($this->providersUrl && isset($data['provider-includes'])) {
            $includes = $data['provider-includes'];
            foreach ($includes as $include => $metadata) {
                $url = $this->baseUrl . '/' . str_replace('%hash%', $metadata['sha256'], $include);
                $cacheKey = str_replace(['%hash%','$'], '', $include);
                if ($this->cache->sha256($cacheKey) === $metadata['sha256']) {
                    $includedData = json_decode($this->cache->read($cacheKey), true);
                } else {
                    $includedData = $this->fetchFile($url, $cacheKey, $metadata['sha256']);
                }

                $this->loadProviderListings($includedData);
            }
        }
    }

    /**
     * @param mixed[] $data
     *
     * @return mixed[]
     */
    private function loadIncludes(array $data): array
    {
        $packages = [];

        // legacy repo handling
        if (!isset($data['packages']) && !isset($data['includes'])) {
            foreach ($data as $pkg) {
                if (isset($pkg['versions']) && is_array($pkg['versions'])) {
                    foreach ($pkg['versions'] as $metadata) {
                        $packages[] = $metadata;
                    }
                }
            }

            return $packages;
        }

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $package => $versions) {
                $packageName = strtolower((string) $package);
                foreach ($versions as $version => $metadata) {
                    $packages[] = $metadata;
                    if (!$this->displayedWarningAboutNonMatchingPackageIndex && $packageName !== strtolower((string) ($metadata['name'] ?? ''))) {
                        $this->displayedWarningAboutNonMatchingPackageIndex = true;
                        $this->io->writeError(sprintf("<warning>Warning: the packages key '%s' doesn't match the name defined in the package metadata '%s' in repository %s</warning>", $package, $metadata['name'] ?? '', $this->baseUrl));
                    }
                }
            }
        }

        if (isset($data['includes'])) {
            foreach ($data['includes'] as $include => $metadata) {
                if (isset($metadata['sha1']) && $this->cache->sha1((string) $include) === $metadata['sha1']) {
                    $includedData = json_decode($this->cache->read((string) $include), true);
                } else {
                    $includedData = $this->fetchFile($include);
                }
                $packages = array_merge($packages, $this->loadIncludes($includedData));
            }
        }

        return $packages;
    }

    /**
     * @param mixed[] $packages
     *
     * @return list<CompletePackage|CompleteAliasPackage>
     */
    private function createPackages(array $packages, ?string $source = null): array
    {
        if (!$packages) {
            return [];
        }

        try {
            foreach ($packages as &$data) {
                if (!isset($data['notification-url'])) {
                    $data['notification-url'] = $this->notifyUrl;
                }
            }

            $packageInstances = $this->loader->loadPackages($packages);

            foreach ($packageInstances as $package) {
                if (isset($this->sourceMirrors[$package->getSourceType()])) {
                    $package->setSourceMirrors($this->sourceMirrors[$package->getSourceType()]);
                }
                $package->setDistMirrors($this->distMirrors);
                $this->configurePackageTransportOptions($package);
            }

            return $packageInstances;
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not load packages '.($packages[0]['name'] ?? json_encode($packages)).' in '.$this->getRepoName().($source ? ' from '.$source : '').': ['.get_class($e).'] '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     */
    protected function fetchFile(string $filename, ?string $cacheKey = null, ?string $sha256 = null, bool $storeLastModifiedTime = false)
    {
        if ('' === $filename) {
            throw new \InvalidArgumentException('$filename should not be an empty string');
        }

        if (null === $cacheKey) {
            $cacheKey = $filename;
            $filename = $this->baseUrl.'/'.$filename;
        }

        // url-encode $ signs in URLs as bad proxies choke on them
        if (($pos = strpos($filename, '$')) && Preg::isMatch('{^https?://}i', $filename)) {
            $filename = substr($filename, 0, $pos) . '%24' . substr($filename, $pos + 1);
        }

        $retries = 3;
        while ($retries--) {
            try {
                $options = $this->options;
                if ($this->eventDispatcher) {
                    $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename, 'metadata', ['repository' => $this]);
                    $preFileDownloadEvent->setTransportOptions($this->options);
                    $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                    $filename = $preFileDownloadEvent->getProcessedUrl();
                    $options = $preFileDownloadEvent->getTransportOptions();
                }

                $response = $this->httpDownloader->get($filename, $options);
                $json = (string) $response->getBody();
                if ($sha256 && $sha256 !== hash('sha256', $json)) {
                    // undo downgrade before trying again if http seems to be hijacked or modifying content somehow
                    if ($this->allowSslDowngrade) {
                        $this->url = str_replace('http://', 'https://', $this->url);
                        $this->baseUrl = str_replace('http://', 'https://', $this->baseUrl);
                        $filename = str_replace('http://', 'https://', $filename);
                    }

                    if ($retries > 0) {
                        usleep(100000);

                        continue;
                    }

                    // TODO use scarier wording once we know for sure it doesn't do false positives anymore
                    throw new RepositorySecurityException('The contents of '.$filename.' do not match its signature. This could indicate a man-in-the-middle attack or e.g. antivirus software corrupting files. Try running composer again and report this if you think it is a mistake.');
                }

                if ($this->eventDispatcher) {
                    $postFileDownloadEvent = new PostFileDownloadEvent(PluginEvents::POST_FILE_DOWNLOAD, null, $sha256, $filename, 'metadata', ['response' => $response, 'repository' => $this]);
                    $this->eventDispatcher->dispatch($postFileDownloadEvent->getName(), $postFileDownloadEvent);
                }

                $data = $response->decodeJson();
                HttpDownloader::outputWarnings($this->io, $this->url, $data);

                if ($cacheKey && !$this->cache->isReadOnly()) {
                    if ($storeLastModifiedTime) {
                        $lastModifiedDate = $response->getHeader('last-modified');
                        if ($lastModifiedDate) {
                            $data['last-modified'] = $lastModifiedDate;
                            $json = JsonFile::encode($data, 0);
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

                if ($e instanceof RepositorySecurityException) {
                    throw $e;
                }

                if ($cacheKey && ($contents = $this->cache->read($cacheKey))) {
                    if (!$this->degradedMode) {
                        $this->io->writeError('<warning>'.$this->url.' could not be fully loaded ('.$e->getMessage().'), package information was loaded from the local cache and may be out of date</warning>');
                    }
                    $this->degradedMode = true;
                    $data = JsonFile::parseJson($contents, $this->cache->getRoot().$cacheKey);

                    break;
                }

                throw $e;
            }
        }

        if (!isset($data)) {
            throw new \LogicException("ComposerRepository: Undefined \$data. Please report at https://github.com/composer/composer/issues/new.");
        }

        return $data;
    }

    /**
     * @return array<mixed>|true
     */
    private function fetchFileIfLastModified(string $filename, string $cacheKey, string $lastModifiedTime)
    {
        if ('' === $filename) {
            throw new \InvalidArgumentException('$filename should not be an empty string');
        }

        try {
            $options = $this->options;
            if ($this->eventDispatcher) {
                $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename, 'metadata', ['repository' => $this]);
                $preFileDownloadEvent->setTransportOptions($this->options);
                $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
                $filename = $preFileDownloadEvent->getProcessedUrl();
                $options = $preFileDownloadEvent->getTransportOptions();
            }

            if (isset($options['http']['header'])) {
                $options['http']['header'] = (array) $options['http']['header'];
            }
            $options['http']['header'][] = 'If-Modified-Since: '.$lastModifiedTime;
            $response = $this->httpDownloader->get($filename, $options);
            $json = (string) $response->getBody();
            if ($json === '' && $response->getStatusCode() === 304) {
                return true;
            }

            if ($this->eventDispatcher) {
                $postFileDownloadEvent = new PostFileDownloadEvent(PluginEvents::POST_FILE_DOWNLOAD, null, null, $filename, 'metadata', ['response' => $response, 'repository' => $this]);
                $this->eventDispatcher->dispatch($postFileDownloadEvent->getName(), $postFileDownloadEvent);
            }

            $data = $response->decodeJson();
            HttpDownloader::outputWarnings($this->io, $this->url, $data);

            $lastModifiedDate = $response->getHeader('last-modified');
            $response->collect();
            if ($lastModifiedDate) {
                $data['last-modified'] = $lastModifiedDate;
                $json = JsonFile::encode($data, 0);
            }
            if (!$this->cache->isReadOnly()) {
                $this->cache->write($cacheKey, $json);
            }

            return $data;
        } catch (\Exception $e) {
            if ($e instanceof \LogicException) {
                throw $e;
            }

            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                throw $e;
            }

            if (!$this->degradedMode) {
                $this->io->writeError('<warning>'.$this->url.' could not be fully loaded ('.$e->getMessage().'), package information was loaded from the local cache and may be out of date</warning>');
            }
            $this->degradedMode = true;

            return true;
        }
    }

    private function asyncFetchFile(string $filename, string $cacheKey, ?string $lastModifiedTime = null): PromiseInterface
    {
        if ('' === $filename) {
            throw new \InvalidArgumentException('$filename should not be an empty string');
        }

        if (isset($this->packagesNotFoundCache[$filename])) {
            return \React\Promise\resolve(['packages' => []]);
        }

        if (isset($this->freshMetadataUrls[$filename]) && $lastModifiedTime) {
            // make it look like we got a 304 response
            return \React\Promise\resolve(true);
        }

        $httpDownloader = $this->httpDownloader;
        $options = $this->options;
        if ($this->eventDispatcher) {
            $preFileDownloadEvent = new PreFileDownloadEvent(PluginEvents::PRE_FILE_DOWNLOAD, $this->httpDownloader, $filename, 'metadata', ['repository' => $this]);
            $preFileDownloadEvent->setTransportOptions($this->options);
            $this->eventDispatcher->dispatch($preFileDownloadEvent->getName(), $preFileDownloadEvent);
            $filename = $preFileDownloadEvent->getProcessedUrl();
            $options = $preFileDownloadEvent->getTransportOptions();
        }

        if ($lastModifiedTime) {
            if (isset($options['http']['header'])) {
                $options['http']['header'] = (array) $options['http']['header'];
            }
            $options['http']['header'][] = 'If-Modified-Since: '.$lastModifiedTime;
        }

        $io = $this->io;
        $url = $this->url;
        $cache = $this->cache;
        $degradedMode = &$this->degradedMode;
        $eventDispatcher = $this->eventDispatcher;

        /**
         * @return array<mixed>|true true if the response was a 304 and the cache is fresh
         */
        $accept = function ($response) use ($io, $url, $filename, $cache, $cacheKey, $eventDispatcher) {
            // package not found is acceptable for a v2 protocol repository
            if ($response->getStatusCode() === 404) {
                $this->packagesNotFoundCache[$filename] = true;

                return ['packages' => []];
            }

            $json = (string) $response->getBody();
            if ($json === '' && $response->getStatusCode() === 304) {
                $this->freshMetadataUrls[$filename] = true;

                return true;
            }

            if ($eventDispatcher) {
                $postFileDownloadEvent = new PostFileDownloadEvent(PluginEvents::POST_FILE_DOWNLOAD, null, null, $filename, 'metadata', ['response' => $response, 'repository' => $this]);
                $eventDispatcher->dispatch($postFileDownloadEvent->getName(), $postFileDownloadEvent);
            }

            $data = $response->decodeJson();
            HttpDownloader::outputWarnings($io, $url, $data);

            $lastModifiedDate = $response->getHeader('last-modified');
            $response->collect();
            if ($lastModifiedDate) {
                $data['last-modified'] = $lastModifiedDate;
                $json = JsonFile::encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (!$cache->isReadOnly()) {
                $cache->write($cacheKey, $json);
            }
            $this->freshMetadataUrls[$filename] = true;

            return $data;
        };

        $reject = function ($e) use ($filename, $accept, $io, $url, &$degradedMode, $lastModifiedTime) {
            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                $this->packagesNotFoundCache[$filename] = true;

                return false;
            }

            if (!$degradedMode) {
                $io->writeError('<warning>'.$url.' could not be fully loaded ('.$e->getMessage().'), package information was loaded from the local cache and may be out of date</warning>');
            }
            $degradedMode = true;

            // if the file is in the cache, we fake a 304 Not Modified to allow the process to continue
            if ($lastModifiedTime) {
                return $accept(new Response(['url' => $url], 304, [], ''));
            }

            // special error code returned when network is being artificially disabled
            if ($e instanceof TransportException && $e->getStatusCode() === 499) {
                return $accept(new Response(['url' => $url], 404, [], ''));
            }

            throw $e;
        };

        return $httpDownloader->add($filename, $options)->then($accept, $reject);
    }

    /**
     * This initializes the packages key of a partial packages.json that contain some packages inlined + a providers-lazy-url
     *
     * This should only be called once
     */
    private function initializePartialPackages(): void
    {
        $rootData = $this->loadRootServerFile();
        if ($rootData === true) {
            return;
        }

        $this->partialPackagesByName = [];
        foreach ($rootData['packages'] as $package => $versions) {
            foreach ($versions as $version) {
                $versionPackageName = strtolower((string) ($version['name'] ?? ''));
                $this->partialPackagesByName[$versionPackageName][] = $version;
                if (!$this->displayedWarningAboutNonMatchingPackageIndex && $versionPackageName !== strtolower($package)) {
                    $this->io->writeError(sprintf("<warning>Warning: the packages key '%s' doesn't match the name defined in the package metadata '%s' in repository %s</warning>", $package, $version['name'] ?? '', $this->baseUrl));
                    $this->displayedWarningAboutNonMatchingPackageIndex = true;
                }
            }
        }

        // wipe rootData as it is fully consumed at this point and this saves some memory
        $this->rootData = true;
    }

    /**
     * Checks if the package name is present in this lazy providers repo
     *
     * @return bool   true if the package name is present in availablePackages or matched by availablePackagePatterns
     */
    protected function lazyProvidersRepoContains(string $name)
    {
        if (!$this->hasAvailablePackageList) {
            throw new \LogicException('lazyProvidersRepoContains should not be called unless hasAvailablePackageList is true');
        }

        if (is_array($this->availablePackages) && isset($this->availablePackages[$name])) {
            return true;
        }

        if (is_array($this->availablePackagePatterns)) {
            foreach ($this->availablePackagePatterns as $providerRegex) {
                if (Preg::isMatch($providerRegex, $name)) {
                    return true;
                }
            }
        }

        return false;
    }
}
