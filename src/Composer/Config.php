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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Config
{
    public static $defaultConfig = array(
        'process-timeout' => 300,
        'vendor-dir' => 'vendor',
        'bin-dir' => '{$vendor-dir}/bin',
        'notify-on-install' => true,
        'github-protocols' => array('git', 'https', 'http'),
    );

    public static $defaultRepositories = array(
        'packagist' => array(
            'type' => 'composer',
            'url' => 'https?://packagist.org',
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
            $this->config = array_replace_recursive($this->config, $config['config']);
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
     * @param  string $key
     * @return mixed
     */
    public function get($key)
    {
        switch ($key) {
            case 'vendor-dir':
            case 'bin-dir':
            case 'process-timeout':
                // convert foo-bar to COMPOSER_FOO_BAR and check if it exists since it overrides the local config
                $env = 'COMPOSER_' . strtoupper(strtr($key, '-', '_'));

                return rtrim($this->process(getenv($env) ?: $this->config[$key]), '/\\');

            case 'home':
                return rtrim($this->process($this->config[$key]), '/\\');

            default:
                if (!isset($this->config[$key])) {
                    return null;
                }

                return $this->process($this->config[$key]);
        }
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
