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
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository
{
    protected $url;
    protected $io;
    protected $packages;
    protected $cache;

    public function __construct(array $config, IOInterface $io)
    {
        if (!preg_match('{^\w+://}', $config['url'])) {
            // assume http as the default protocol
            $config['url'] = 'http://'.$config['url'];
        }
        $config['url'] = rtrim($config['url'], '/');
        if (function_exists('filter_var') && !filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for Composer repository: '.$config['url']);
        }

        $this->url = $config['url'];
        $this->io = $io;
        $this->cache = new Cache($io, preg_replace('{[^a-z0-9.]}', '-', $this->url));
    }

    protected function initialize()
    {
        parent::initialize();

        try {
            $json = new JsonFile($this->url.'/packages.json', new RemoteFilesystem($this->io));
            $data = $json->read();
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
