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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Config
{
    private $config;

    public function __construct()
    {
        // load defaults
        $this->config = array(
            'process-timeout' => 300,
            'vendor-dir' => 'vendor',
            'bin-dir' => '{$vendor-dir}/bin',
            'notify-on-install' => true,
        );
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
    }

    /**
     * Returns a setting
     *
     * @param string $key
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
                return $this->process(getenv($env) ?: $this->config[$key]);

            case 'home':
                return rtrim($this->process($this->config[$key]), '/\\');

            default:
                return $this->process($this->config[$key]);
        }
    }

    /**
     * Checks whether a setting exists
     *
     * @param string $key
     * @return Boolean
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
        return preg_replace_callback('#\{\$(.+)\}#', function ($match) use ($config) {
            return $config->get($match[1]);
        }, $value);
    }
}
