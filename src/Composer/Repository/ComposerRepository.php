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
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;
use Composer\Downloader\TransportException;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository implements NotifiableRepositoryInterface, StreamableRepositoryInterface
{
    protected $config;
    protected $options;
    protected $url;
    protected $io;
    protected $cache;
    protected $notifyUrl;
    protected $providersUrl;
    protected $providers = array();
    protected $providersByUid = array();
    protected $loader;
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

        if (function_exists('filter_var') && version_compare(PHP_VERSION, '5.3.3', '>=') && !filter_var($repoConfig['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for Composer repository: '.$repoConfig['url']);
        }

        if (!isset($repoConfig['options'])) {
            $repoConfig['options'] = array();
        }

        $this->config = $config;
        $this->options = $repoConfig['options'];
        $this->url = $repoConfig['url'];
        $this->io = $io;
        $this->cache = new Cache($io, $config->get('home').'/cache/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url));
        $this->loader = new ArrayLoader();
    }

    /**
     * {@inheritDoc}
     */
    public function notifyInstall(PackageInterface $package)
    {
        if (!$this->notifyUrl || !$this->config->get('notify-on-install')) {
            return;
        }

        // TODO use an optional curl_multi pool for all the notifications
        $url = str_replace('%package%', $package->getPrettyName(), $this->notifyUrl);

        $params = array(
            'version' => $package->getPrettyVersion(),
            'version_normalized' => $package->getVersion(),
        );
        $opts = array('http' =>
            array(
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query($params, '', '&'),
                'timeout' => 3,
            )
        );

        $context = stream_context_create($opts);
        @file_get_contents($url, false, $context);
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

        return null !== $this->providersUrl;
    }

    public function whatProvides(Pool $pool, $name)
    {
        if ($name === 'php' || in_array(substr($name, 0, 4), array('ext-', 'lib-'), true) || $name === '__root__') {
            return array();
        }

        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        $url = str_replace('%package%', strtolower($name), $this->providersUrl);

        try {
            $json = new JsonFile($url, new RemoteFilesystem($this->io));
            $packages = $json->read();
        } catch (\RuntimeException $e) {
            if (!$e->getPrevious() instanceof TransportException || $e->getPrevious()->getCode() !== 404) {
                throw $e;
            }
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
                    }
                } else {
                    if (!$pool->isPackageAcceptable($version['name'], VersionParser::parseStability($version['version']))) {
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

        if (!empty($data['notify'])) {
            if ('/' === $data['notify'][0]) {
                $this->notifyUrl = preg_replace('{(https?://[^/]+).*}i', '$1' . $data['notify'], $this->url);
            } else {
                $this->notifyUrl = $data['notify'];
            }
        }

        if (!empty($data['providers'])) {
            if ('/' === $data['providers'][0]) {
                $this->providersUrl = preg_replace('{(https?://[^/]+).*}i', '$1' . $data['providers'], $this->url);
            } else {
                $this->providersUrl = $data['providers'];
            }
        }

        return $this->rootData = $data;
    }

    protected function loadDataFromServer()
    {
        $data = $this->loadRootServerFile();

        return $this->loadIncludes($data);
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

            return;
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
            return $this->loader->load($data, 'Composer\Package\CompletePackage');
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not load package '.(isset($data['name']) ? $data['name'] : json_encode($data)).' in '.$this->url.': ['.get_class($e).'] '.$e->getMessage(), 0, $e);
        }
    }

    protected function fetchFile($filename, $cacheKey = null)
    {
        if (!$cacheKey) {
            $cacheKey = $filename;
            $filename = $this->url.'/'.$filename;
        }

        $retries = 3;
        while ($retries--) {
            try {
                $json = new JsonFile($filename, new RemoteFilesystem($this->io, $this->options));
                $data = $json->read();
                $this->cache->write($cacheKey, json_encode($data));

                break;
            } catch (\Exception $e) {
                if ($contents = $this->cache->read($cacheKey)) {
                    if (!$this->degradedMode) {
                        $this->io->write('<warning>'.$e->getMessage().'</warning>');
                        $this->io->write('<warning>'.$this->url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
                    }
                    $this->degradedMode = true;
                    $data = json_decode($contents, true);

                    break;
                } elseif (!$retries) {
                    throw $e;
                }

                usleep(100);
            }
        }

        return $data;
    }
}
