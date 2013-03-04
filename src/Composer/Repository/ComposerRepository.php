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
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository implements StreamableRepositoryInterface
{
    protected $config;
    protected $options;
    protected $url;
    protected $baseUrl;
    protected $io;
    protected $rfs;
    protected $cache;
    protected $notifyUrl;
    protected $hasProviders = false;
    protected $providersUrl;
    protected $providerListing;
    protected $providers = array();
    protected $providersByUid = array();
    protected $loader;
    protected $rootAliases;
    protected $allowSslDowngrade = false;
    private $rawData;
    private $minimalPackages;
    private $degradedMode = false;
    private $rootData;

    public function __construct(array $repoConfig, IOInterface $io, Config $config)
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
        if (empty($urlBits['scheme']) || empty($urlBits['host'])) {
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
        $this->baseUrl = rtrim(preg_replace('{^(.*)(?:/packages.json)?(?:[?#].*)?$}', '$1', $this->url), '/');
        $this->io = $io;
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url), 'a-z0-9.$');
        $this->loader = new ArrayLoader();
        $this->rfs = new RemoteFilesystem($this->io, $this->options);
    }

    public function setRootAliases(array $rootAliases)
    {
        $this->rootAliases = $rootAliases;
    }

    /**
     * {@inheritDoc}
     */
    public function getMinimalPackages()
    {
        if (isset($this->minimalPackages)) {
            return $this->minimalPackages;
        }

        if (null === $this->rawData) {
            $this->rawData = $this->loadDataFromServer();
        }

        $this->minimalPackages = array();
        $versionParser = new VersionParser;

        foreach ($this->rawData as $package) {
            $version = !empty($package['version_normalized']) ? $package['version_normalized'] : $versionParser->normalize($package['version']);
            $data = array(
                'name' => strtolower($package['name']),
                'repo' => $this,
                'version' => $version,
                'raw' => $package,
            );
            if (!empty($package['replace'])) {
                $data['replace'] = $package['replace'];
            }
            if (!empty($package['provide'])) {
                $data['provide'] = $package['provide'];
            }

            // add branch aliases
            if ($aliasNormalized = $this->loader->getBranchAlias($package)) {
                $data['alias'] = preg_replace('{(\.9{7})+}', '.x', $aliasNormalized);
                $data['alias_normalized'] = $aliasNormalized;
            }

            $this->minimalPackages[] = $data;
        }

        return $this->minimalPackages;
    }

    /**
     * {@inheritDoc}
     */
    public function filterPackages($callback, $class = 'Composer\Package\Package')
    {
        if (null === $this->rawData) {
            $this->rawData = $this->loadDataFromServer();
        }

        foreach ($this->rawData as $package) {
            if (false === call_user_func($callback, $package = $this->createPackage($package, $class))) {
                return false;
            }
            if ($package->getAlias()) {
                if (false === call_user_func($callback, $this->createAliasPackage($package))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function loadPackage(array $data)
    {
        $package = $this->createPackage($data['raw'], 'Composer\Package\Package');
        $package->setRepository($this);

        return $package;
    }

    /**
     * {@inheritDoc}
     */
    public function loadAliasPackage(array $data, PackageInterface $aliasOf)
    {
        $aliasPackage = $this->createAliasPackage($aliasOf, $data['version'], $data['alias']);
        $aliasPackage->setRepository($this);

        return $aliasPackage;
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

    public function whatProvides(Pool $pool, $name)
    {
        // skip platform packages
        if ($name === 'php' || in_array(substr($name, 0, 4), array('ext-', 'lib-'), true) || $name === '__root__') {
            return array();
        }

        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        if (null === $this->providerListing) {
            $this->loadProviderListings($this->loadRootServerFile());
        }

        if ($this->providersUrl) {
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

        if ($this->cache->sha256($cacheKey) === $hash) {
            $packages = json_decode($this->cache->read($cacheKey), true);
        } else {
            $packages = $this->fetchFile($url, $cacheKey, $hash);
        }

        $this->providers[$name] = array();
        foreach ($packages['packages'] as $versions) {
            foreach ($versions as $version) {
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
                    if (!$pool->isPackageAcceptable(strtolower($version['name']), VersionParser::parseStability($version['version']))) {
                        continue;
                    }

                    // load acceptable packages in the providers
                    $package = $this->createPackage($version, 'Composer\Package\Package');
                    $package->setRepository($this);

                    $this->providers[$name][$version['uid']] = $package;
                    $this->providersByUid[$version['uid']] = $package;

                    if ($package->getAlias()) {
                        $alias = $this->createAliasPackage($package);
                        $alias->setRepository($this);

                        $this->providers[$name][$version['uid'].'-alias'] = $alias;
                        // override provider with its alias so it can be expanded in the if block above
                        $this->providersByUid[$version['uid']] = $alias;
                    }

                    // handle root package aliases
                    unset($rootAliasData);

                    if (isset($this->rootAliases[$name][$package->getVersion()])) {
                        $rootAliasData = $this->rootAliases[$name][$package->getVersion()];
                    } elseif (($aliasNormalized = $package->getAlias()) && isset($this->rootAliases[$name][$aliasNormalized])) {
                        $rootAliasData = $this->rootAliases[$name][$aliasNormalized];
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

        return $this->providers[$name];
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

    protected function loadRootServerFile()
    {
        if (null !== $this->rootData) {
            return $this->rootData;
        }

        if (!extension_loaded('openssl') && 'https' === substr($this->url, 0, 5)) {
            throw new \RuntimeException('You must enable the openssl extension in your php.ini to load information from '.$this->url);
        }

        $jsonUrlParts = parse_url($this->url);

        if (isset($jsonUrlParts['path']) && false !== strpos($jsonUrlParts['path'], '/packages.json')) {
            $jsonUrl = $this->url;
        } else {
            $jsonUrl = $this->url . '/packages.json';
        }

        $data = $this->fetchFile($jsonUrl, 'packages.json');

        // TODO remove this BC notify_batch support
        if (!empty($data['notify_batch'])) {
            $notifyBatchUrl = $data['notify_batch'];
        }
        if (!empty($data['notify-batch'])) {
            $notifyBatchUrl = $data['notify-batch'];
        }
        if (!empty($notifyBatchUrl)) {
            if ('/' === $notifyBatchUrl[0]) {
                $this->notifyUrl = preg_replace('{(https?://[^/]+).*}i', '$1' . $notifyBatchUrl, $this->url);
            } else {
                $this->notifyUrl = $notifyBatchUrl;
            }
        }

        if (!$this->notifyUrl && !empty($data['notify'])) {
            if ('/' === $data['notify'][0]) {
                $this->notifyUrl = preg_replace('{(https?://[^/]+).*}i', '$1' . $data['notify'], $this->url);
            } else {
                $this->notifyUrl = $data['notify'];
            }
        }

        if ($this->allowSslDowngrade) {
            $this->url = str_replace('https://', 'http://', $this->url);
        }

        if (!empty($data['providers-url'])) {
            if ('/' === $data['providers-url'][0]) {
                $this->providersUrl = preg_replace('{(https?://[^/]+).*}i', '$1' . $data['providers-url'], $this->url);
            } else {
                $this->providersUrl = $data['providers-url'];
            }
            $this->hasProviders = true;
        }

        if (!empty($data['providers']) || !empty($data['providers-includes'])) {
            $this->hasProviders = true;
        }

        return $this->rootData = $data;
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
            $data['notification-url'] = $this->notifyUrl;

            return $this->loader->load($data, 'Composer\Package\CompletePackage');
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not load package '.(isset($data['name']) ? $data['name'] : json_encode($data)).' in '.$this->url.': ['.get_class($e).'] '.$e->getMessage(), 0, $e);
        }
    }

    protected function fetchFile($filename, $cacheKey = null, $sha256 = null)
    {
        if (!$cacheKey) {
            $cacheKey = $filename;
            $filename = $this->baseUrl.'/'.$filename;
        }

        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($filename, $filename, false);
                if ($sha256 && $sha256 !== hash('sha256', $json)) {
                    if ($retries) {
                        usleep(100);

                        continue;
                    }

                    // TODO use scarier wording once we know for sure it doesn't do false positives anymore
                    throw new RepositorySecurityException('The contents of '.$filename.' do not match its signature. This should indicate a man-in-the-middle attack. Try running composer again and report this if you think it is a mistake.');
                }
                $data = JsonFile::parseJson($json, $filename);
                $this->cache->write($cacheKey, $json);

                break;
            } catch (\Exception $e) {
                if ($retries) {
                    usleep(100);
                    continue;
                }

                // in case the remote filesystem responds with an 401 error ask for credentials
                if($e instanceof TransportException && ($e->getCode() == 401))
                {
                    $this->io->write('Enter the access credentials needed to access the repository');
                    $username = $this->io->ask('Username: ');
                    $password = $this->io->askAndHideAnswer('Password: ');
                    $this->rfs->setAuthentication($filename, $username, $password);

                    // try fetching the file again
                    return $this->fetchFile($filename, $cacheKey, $sha256);
                }

                if ($e instanceof RepositorySecurityException) {
                    throw $e;
                }

                if ($contents = $this->cache->read($cacheKey)) {
                    if (!$this->degradedMode) {
                        $this->io->write('<warning>'.$e->getMessage().'</warning>');
                        $this->io->write('<warning>'.$this->url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
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
