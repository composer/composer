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
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\PackageInterface;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository implements NotifiableRepositoryInterface
{
    protected $config;
    protected $url;
    protected $io;
    protected $packages;
    protected $cache;
    protected $notifyUrl;

    public function __construct(array $repoConfig, IOInterface $io, Config $config)
    {
        if (!preg_match('{^\w+://}', $repoConfig['url'])) {
            // assume http as the default protocol
            $repoConfig['url'] = 'http://'.$repoConfig['url'];
        }
        $repoConfig['url'] = rtrim($repoConfig['url'], '/');
        if (function_exists('filter_var') && version_compare(PHP_VERSION, '5.3.3', '>=') && !filter_var($repoConfig['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for Composer repository: '.$repoConfig['url']);
        }

        $this->config = $config;
        $this->url = $repoConfig['url'];
        $this->io = $io;
        $this->cache = new Cache($io, $config->get('home').'/cache/'.preg_replace('{[^a-z0-9.]}', '-', $this->url));
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

    protected function initialize()
    {
        parent::initialize();

        try {
            $json = new JsonFile($this->url.'/packages.json', new RemoteFilesystem($this->io));
            $data = $json->read();

            if (!empty($data['notify'])) {
                $this->notifyUrl = preg_replace('{(https?://[^/]+).*}i', '$1' . $data['notify'], $this->url);
            }

            $this->cache->write('packages.json', json_encode($data));
        } catch (\Exception $e) {
            if ($contents = $this->cache->read('packages.json')) {
                $this->io->write('<warning>'.$this->url.' could not be loaded, package information was loaded from the local cache and may be out of date</warning>');
                $data = json_decode($contents, true);
            } else {
                throw $e;
            }
        }

        $loader = new ArrayLoader();
        $this->loadRepository($loader, $data);
    }

    protected function loadRepository(ArrayLoader $loader, $data)
    {
        // legacy repo handling
        if (!isset($data['packages']) && !isset($data['includes'])) {
            foreach ($data as $pkg) {
                foreach ($pkg['versions'] as $metadata) {
                    $this->addPackage($loader->load($metadata));
                }
            }

            return;
        }

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $package => $versions) {
                foreach ($versions as $version => $metadata) {
                    $this->addPackage($loader->load($metadata));
                }
            }
        }

        if (isset($data['includes'])) {
            foreach ($data['includes'] as $include => $metadata) {
                if ($this->cache->sha1($include) === $metadata['sha1']) {
                    $includedData = json_decode($this->cache->read($include), true);
                } else {
                    $json = new JsonFile($this->url.'/'.$include, new RemoteFilesystem($this->io));
                    $includedData = $json->read();
                    $this->cache->write($include, json_encode($includedData));
                }
                $this->loadRepository($loader, $includedData);
            }
        }
    }
}
