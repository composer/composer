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
        'use-include-path' => false,
        'preferred-install' => 'auto',
        'notify-on-install' => true,
        'github-protocols' => array('git', 'https', 'ssh'),
        'vendor-dir' => 'vendor',
        'bin-dir' => '{$vendor-dir}/bin',
        'cache-dir' => '{$home}/cache',
        'cache-files-dir' => '{$cache-dir}/files',
        'cache-repo-dir' => '{$cache-dir}/repo',
        'cache-vcs-dir' => '{$cache-dir}/vcs',
        'cache-ttl' => 15552000, // 6 months
        'cache-files-ttl' => null, // fallback to cache-ttl
        'cache-files-maxsize' => '300MiB',
        'discard-changes' => false,
        'autoloader-suffix' => null,
        'optimize-autoloader' => false,
        'prepend-autoloader' => true,
        'github-domains' => array('github.com'),
        'github-expose-hostname' => true,
        'store-auths' => 'prompt',
        // valid keys without defaults (auth config stuff):
        // github-oauth
        // http-basic
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
    private $authConfigSource;
    private $useEnvironment;

    /**
     * @param boolean $useEnvironment Use COMPOSER_ environment variables to replace config settings
     */
    public function __construct($useEnvironment = true)
    {
        // load defaults
        $this->config = static::$defaultConfig;
        $this->repositories = static::$defaultRepositories;
        $this->useEnvironment = (bool) $useEnvironment;
    }

    public function setConfigSource(ConfigSourceInterface $source)
    {
        $this->configSource = $source;
    }

    public function getConfigSource()
    {
        return $this->configSource;
    }

    public function setAuthConfigSource(ConfigSourceInterface $source)
    {
        $this->authConfigSource = $source;
    }

    public function getAuthConfigSource()
    {
        return $this->authConfigSource;
    }

    /**
     * Merges new config values with the existing ones (overriding)
     *
     * @param array $config
     */
    public function merge($config)
    {
        // override defaults with given config
        if (!empty($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $val) {
                if (in_array($key, array('github-oauth', 'http-basic')) && isset($this->config[$key])) {
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
            case 'vendor-dir':
            case 'bin-dir':
            case 'process-timeout':
            case 'cache-dir':
            case 'cache-files-dir':
            case 'cache-repo-dir':
            case 'cache-vcs-dir':
                // convert foo-bar to COMPOSER_FOO_BAR and check if it exists since it overrides the local config
                $env = 'COMPOSER_' . strtoupper(strtr($key, '-', '_'));

                $val = rtrim($this->process($this->getComposerEnv($env) ?: $this->config[$key]), '/\\');
                $val = preg_replace('#^(\$HOME|~)(/|$)#', rtrim(getenv('HOME') ?: getenv('USERPROFILE'), '/\\') . '/', $val);

                return $val;

            case 'cache-ttl':
                return (int) $this->config[$key];

            case 'cache-files-maxsize':
                if (!preg_match('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', $this->config[$key], $matches)) {
                    throw new \RuntimeException(
                        "Could not parse the value of 'cache-files-maxsize': {$this->config[$key]}"
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

            case 'cache-files-ttl':
                if (isset($this->config[$key])) {
                    return (int) $this->config[$key];
                }

                return (int) $this->config['cache-ttl'];

            case 'home':
                return rtrim($this->process($this->config[$key]), '/\\');

            case 'discard-changes':
                if ($env = $this->getComposerEnv('COMPOSER_DISCARD_CHANGES')) {
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
                        "Invalid value for 'discard-changes': {$this->config[$key]}. Expected true, false or stash"
                    );
                }

                return $this->config[$key];

            case 'github-protocols':
                if (reset($this->config['github-protocols']) === 'http') {
                    throw new \RuntimeException('The http protocol for github is not available anymore, update your config\'s github-protocols to use "https", "git" or "ssh"');
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
     * @param  string $value a config string that can contain {$refs-to-other-config}
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

    /**
     * Reads the value of a Composer environment variable
     *
     * This should be used to read COMPOSER_ environment variables
     * that overload config values.
     *
     * @param  string         $var
     * @return string|boolean
     */
    private function getComposerEnv($var)
    {
        if ($this->useEnvironment) {
            return getenv($var);
        }

        return false;
    }
}
