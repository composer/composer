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

namespace Composer;

use Composer\Config\ConfigSourceInterface;
use Composer\Config\Setting;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Config
{
    public static $defaultConfig = array(
        Setting::BIN_DIR => '{$vendor-dir}/bin',
        Setting::CACHE_DIR => '{$home}/cache',
        Setting::CACHE_FILES_DIR => '{$cache-dir}/files',
        Setting::CACHE_FILES_MAXSIZE => '300MiB',
        Setting::CACHE_FILES_TTL => null, // fallback to cache-ttl
        Setting::CACHE_REPO_DIR => '{$cache-dir}/repo',
        Setting::CACHE_TTL => 15552000, // 6 months
        Setting::CACHE_VCS_DIR => '{$cache-dir}/vcs',
        Setting::DISCARD_CHANGES => false,
        Setting::GITHUB_DOMAINS => array('github.com'),
        Setting::GITHUB_PROTOCOLS => array('git', 'https'),
        Setting::MINIMUM_STABILITY => 'stable',
        Setting::NOTIFY_ON_INSTALL => true,
        Setting::PREFER_STABLE => false,
        Setting::PREFERRED_INSTALL => 'auto',
        Setting::PREPEND_AUTOLOADER => true,
        Setting::PROCESS_TIMEOUT => 300,
        Setting::USE_INCLUDE_PATH => false,
        Setting::VENDOR_DIR => 'vendor',
    );

    public static $defaultRepositories = array(
        'packagist' => array(
            'type' => 'composer',
            'url' => 'https?://packagist.org',
            'allow_ssl_downgrade' => true,
        )
    );

    private $config;
    private $repositories;
    private $configSource;

    public function __construct()
    {
        // load defaults
        $this->config = static::$defaultConfig;
        $this->repositories = static::$defaultRepositories;
    }

    public function setConfigSource(ConfigSourceInterface $source)
    {
        $this->configSource = $source;
    }

    public function getConfigSource()
    {
        return $this->configSource;
    }

    /**
     * Merges new config values with the existing ones (overriding)
     *
     * @param array $config
     */
    public function merge(array $config)
    {
        // override defaults with given config
        if (!empty($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $val) {
                if (in_array($key, array('github-oauth')) && isset($this->config[$key])) {
                    $this->config[$key] = array_merge($this->config[$key], $val);
                } else {
                    $this->config[$key] = $val;
                }
            }
        }

        if (!empty($config['repositories']) && is_array($config['repositories'])) {
            $this->repositories = array_reverse($this->repositories, true);
            $newRepos = array_reverse($config['repositories'], true);
            foreach ($newRepos as $name => $repository) {
                // disable a repository by name
                if (false === $repository) {
                    unset($this->repositories[$name]);
                    continue;
                }

                // disable a repository with an anonymous {"name": false} repo
                if (1 === count($repository) && false === current($repository)) {
                    unset($this->repositories[key($repository)]);
                    continue;
                }

                // store repo
                if (is_int($name)) {
                    $this->repositories[] = $repository;
                } else {
                    $this->repositories[$name] = $repository;
                }
            }
            $this->repositories = array_reverse($this->repositories, true);
        }
    }

    /**
     * @return array
     */
    public function getRepositories()
    {
        return $this->repositories;
    }

    /**
     * Returns a setting
     *
     * @param  string            $key
     * @throws \RuntimeException
     * @return mixed
     */
    public function get($key)
    {
        switch ($key) {
            case Setting::VENDOR_DIR:
            case Setting::BIN_DIR:
            case Setting::PROCESS_TIMEOUT:
            case Setting::CACHE_DIR:
            case Setting::CACHE_FILES_DIR:
            case Setting::CACHE_REPO_DIR:
            case Setting::CACHE_VCS_DIR:
                // convert foo-bar to COMPOSER_FOO_BAR and check if it exists since it overrides the local config
                $env = 'COMPOSER_' . strtoupper(strtr($key, '-', '_'));

                return rtrim($this->process(getenv($env) ?: $this->config[$key]), '/\\');

            case Setting::CACHE_TTL:
                return (int) $this->config[$key];

            case Setting::CACHE_FILES_MAXSIZE:
                if (!preg_match('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', $this->config[$key], $matches)) {
                    throw new \RuntimeException(
                        "Could not parse the value of '" . Setting::CACHE_FILES_MAXSIZE . "': {$this->config[$key]}"
                    );
                }
                $size = $matches[1];
                if (isset($matches[2])) {
                    switch (strtolower($matches[2])) {
                        case 'g':
                            $size *= 1024;
                            // intentional fallthrough
                        case 'm':
                            $size *= 1024;
                            // intentional fallthrough
                        case 'k':
                            $size *= 1024;
                            break;
                    }
                }

                return $size;

            case Setting::CACHE_FILES_TTL:
                if (isset($this->config[$key])) {
                    return (int) $this->config[$key];
                }

                return (int) $this->config[Setting::CACHE_TTL];

            case 'home':
                return rtrim($this->process($this->config[$key]), '/\\');

            case Setting::DISCARD_CHANGES:
                if ($env = getenv('COMPOSER_DISCARD_CHANGES')) {
                    if (!in_array($env, array('stash', 'true', 'false', '1', '0'), true)) {
                        throw new \RuntimeException(
                            "Invalid value for COMPOSER_DISCARD_CHANGES: {$env}. Expected 1, 0, true, false or stash"
                        );
                    }
                    if ('stash' === $env) {
                        return 'stash';
                    }

                    // convert string value to bool
                    return $env !== 'false' && (bool) $env;
                }

                if (!in_array($this->config[$key], array(true, false, 'stash'), true)) {
                    throw new \RuntimeException(
                        "Invalid value for '"
                        . Setting::DISCARD_CHANGES
                        . "'}: {$this->config[$key]}. Expected true, false or stash"
                    );
                }

                return $this->config[$key];

            case Setting::GITHUB_PROTOCOLS:
                if (reset($this->config[Setting::GITHUB_PROTOCOLS]) === 'http') {
                    throw new \RuntimeException('The http protocol for github is not available anymore, update your config\'s github-protocols to use "https" or "git"');
                }

                return $this->config[$key];

            default:
                if (!isset($this->config[$key])) {
                    return null;
                }

                return $this->process($this->config[$key]);
        }
    }

    public function all()
    {
        $all = array(
            'repositories' => $this->getRepositories(),
        );
        foreach (array_keys($this->config) as $key) {
            $all['config'][$key] = $this->get($key);
        }

        return $all;
    }

    public function raw()
    {
        return array(
            'repositories' => $this->getRepositories(),
            'config' => $this->config,
        );
    }

    /**
     * Checks whether a setting exists
     *
     * @param  string $key
     * @return bool
     */
    public function has($key)
    {
        return array_key_exists($key, $this->config);
    }

    /**
     * Replaces {$refs} inside a config string
     *
     * @param string a config string that can contain {$refs-to-other-config}
     * @return string
     */
    private function process($value)
    {
        $config = $this;

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback('#\{\$(.+)\}#', function ($match) use ($config) {
            return $config->get($match[1]);
        }, $value);
    }
}
