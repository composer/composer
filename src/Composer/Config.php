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

namespace Composer;

use Composer\Config\ConfigSourceInterface;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Config
{
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_COMMAND = 'command';
    public const SOURCE_UNKNOWN = 'unknown';

    public const RELATIVE_PATHS = 1;

    /** @var array<string, mixed> */
    public static $defaultConfig = array(
        'process-timeout' => 300,
        'use-include-path' => false,
        'allow-plugins' => null, // null for BC for now, will become array() after July 2022
        'use-parent-dir' => 'prompt',
        'preferred-install' => 'dist',
        'notify-on-install' => true,
        'github-protocols' => array('https', 'ssh', 'git'),
        'gitlab-protocol' => null,
        'vendor-dir' => 'vendor',
        'bin-dir' => '{$vendor-dir}/bin',
        'cache-dir' => '{$home}/cache',
        'data-dir' => '{$home}',
        'cache-files-dir' => '{$cache-dir}/files',
        'cache-repo-dir' => '{$cache-dir}/repo',
        'cache-vcs-dir' => '{$cache-dir}/vcs',
        'cache-ttl' => 15552000, // 6 months
        'cache-files-ttl' => null, // fallback to cache-ttl
        'cache-files-maxsize' => '300MiB',
        'cache-read-only' => false,
        'bin-compat' => 'auto',
        'discard-changes' => false,
        'autoloader-suffix' => null,
        'sort-packages' => false,
        'optimize-autoloader' => false,
        'classmap-authoritative' => false,
        'apcu-autoloader' => false,
        'prepend-autoloader' => true,
        'github-domains' => array('github.com'),
        'bitbucket-expose-hostname' => true,
        'disable-tls' => false,
        'secure-http' => true,
        'secure-svn-domains' => array(),
        'cafile' => null,
        'capath' => null,
        'github-expose-hostname' => true,
        'gitlab-domains' => array('gitlab.com'),
        'store-auths' => 'prompt',
        'platform' => array(),
        'archive-format' => 'tar',
        'archive-dir' => '.',
        'htaccess-protect' => true,
        'use-github-api' => true,
        'lock' => true,
        'platform-check' => 'php-only',
        'bitbucket-oauth' => array(),
        'github-oauth' => array(),
        'gitlab-oauth' => array(),
        'gitlab-token' => array(),
        'http-basic' => array(),
        'bearer' => array(),
    );

    /** @var array<string, mixed> */
    public static $defaultRepositories = array(
        'packagist.org' => array(
            'type' => 'composer',
            'url' => 'https://repo.packagist.org',
        ),
    );

    /** @var array<string, mixed> */
    private $config;
    /** @var ?string */
    private $baseDir;
    /** @var array<int|string, mixed> */
    private $repositories;
    /** @var ConfigSourceInterface */
    private $configSource;
    /** @var ConfigSourceInterface */
    private $authConfigSource;
    /** @var bool */
    private $useEnvironment;
    /** @var array<string, true> */
    private $warnedHosts = array();
    /** @var array<string, true> */
    private $sslVerifyWarnedHosts = array();
    /** @var array<string, string> */
    private $sourceOfConfigValue = array();

    /**
     * @param bool    $useEnvironment Use COMPOSER_ environment variables to replace config settings
     * @param ?string $baseDir        Optional base directory of the config
     */
    public function __construct(bool $useEnvironment = true, ?string $baseDir = null)
    {
        // load defaults
        $this->config = static::$defaultConfig;

        // TODO after July 2022 remove this and update the default value above in self::$defaultConfig + remove note from 06-config.md
        if (strtotime('2022-07-01') < time()) {
            $this->config['allow-plugins'] = array();
        }

        $this->repositories = static::$defaultRepositories;
        $this->useEnvironment = (bool) $useEnvironment;
        $this->baseDir = is_string($baseDir) && '' !== $baseDir ? $baseDir : null;

        foreach ($this->config as $configKey => $configValue) {
            $this->setSourceOfConfigValue($configValue, $configKey, self::SOURCE_DEFAULT);
        }

        foreach ($this->repositories as $configKey => $configValue) {
            $this->setSourceOfConfigValue($configValue, 'repositories.' . $configKey, self::SOURCE_DEFAULT);
        }
    }

    /**
     * @return void
     */
    public function setConfigSource(ConfigSourceInterface $source): void
    {
        $this->configSource = $source;
    }

    /**
     * @return ConfigSourceInterface
     */
    public function getConfigSource(): ConfigSourceInterface
    {
        return $this->configSource;
    }

    /**
     * @return void
     */
    public function setAuthConfigSource(ConfigSourceInterface $source): void
    {
        $this->authConfigSource = $source;
    }

    /**
     * @return ConfigSourceInterface
     */
    public function getAuthConfigSource(): ConfigSourceInterface
    {
        return $this->authConfigSource;
    }

    /**
     * Merges new config values with the existing ones (overriding)
     *
     * @param array{config?: array<string, mixed>, repositories?: array<mixed>} $config
     * @param string $source
     *
     * @return void
     */
    public function merge(array $config, string $source = self::SOURCE_UNKNOWN): void
    {
        // override defaults with given config
        if (!empty($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $val) {
                if (in_array($key, array('bitbucket-oauth', 'github-oauth', 'gitlab-oauth', 'gitlab-token', 'http-basic', 'bearer'), true) && isset($this->config[$key])) {
                    $this->config[$key] = array_merge($this->config[$key], $val);
                    $this->setSourceOfConfigValue($val, $key, $source);
                } elseif (in_array($key, array('allow-plugins'), true) && isset($this->config[$key]) && is_array($this->config[$key])) {
                    // merging $val first to get the local config on top of the global one, then appending the global config,
                    // then merging local one again to make sure the values from local win over global ones for keys present in both
                    $this->config[$key] = array_merge($val, $this->config[$key], $val);
                    $this->setSourceOfConfigValue($val, $key, $source);
                } elseif (in_array($key, array('gitlab-domains', 'github-domains'), true) && isset($this->config[$key])) {
                    $this->config[$key] = array_unique(array_merge($this->config[$key], $val));
                    $this->setSourceOfConfigValue($val, $key, $source);
                } elseif ('preferred-install' === $key && isset($this->config[$key])) {
                    if (is_array($val) || is_array($this->config[$key])) {
                        if (is_string($val)) {
                            $val = array('*' => $val);
                        }
                        if (is_string($this->config[$key])) {
                            $this->config[$key] = array('*' => $this->config[$key]);
                            $this->sourceOfConfigValue[$key . '*'] = $source;
                        }
                        $this->config[$key] = array_merge($this->config[$key], $val);
                        $this->setSourceOfConfigValue($val, $key, $source);
                        // the full match pattern needs to be last
                        if (isset($this->config[$key]['*'])) {
                            $wildcard = $this->config[$key]['*'];
                            unset($this->config[$key]['*']);
                            $this->config[$key]['*'] = $wildcard;
                        }
                    } else {
                        $this->config[$key] = $val;
                        $this->setSourceOfConfigValue($val, $key, $source);
                    }
                } else {
                    $this->config[$key] = $val;
                    $this->setSourceOfConfigValue($val, $key, $source);
                }
            }
        }

        if (!empty($config['repositories']) && is_array($config['repositories'])) {
            $this->repositories = array_reverse($this->repositories, true);
            $newRepos = array_reverse($config['repositories'], true);
            foreach ($newRepos as $name => $repository) {
                // disable a repository by name
                if (false === $repository) {
                    $this->disableRepoByName((string) $name);
                    continue;
                }

                // disable a repository with an anonymous {"name": false} repo
                if (is_array($repository) && 1 === count($repository) && false === current($repository)) {
                    $this->disableRepoByName((string) key($repository));
                    continue;
                }

                // auto-deactivate the default packagist.org repo if it gets redefined
                if (isset($repository['type'], $repository['url']) && $repository['type'] === 'composer' && Preg::isMatch('{^https?://(?:[a-z0-9-.]+\.)?packagist.org(/|$)}', $repository['url'])) {
                    $this->disableRepoByName('packagist.org');
                }

                // store repo
                if (is_int($name)) {
                    $this->repositories[] = $repository;
                    $this->setSourceOfConfigValue($repository, 'repositories.' . array_search($repository, $this->repositories, true), $source);
                } else {
                    if ($name === 'packagist') { // BC support for default "packagist" named repo
                        $this->repositories[$name . '.org'] = $repository;
                        $this->setSourceOfConfigValue($repository, 'repositories.' . $name . '.org', $source);
                    } else {
                        $this->repositories[$name] = $repository;
                        $this->setSourceOfConfigValue($repository, 'repositories.' . $name, $source);
                    }
                }
            }
            $this->repositories = array_reverse($this->repositories, true);
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * Returns a setting
     *
     * @param  string            $key
     * @param  int               $flags Options (see class constants)
     * @throws \RuntimeException
     *
     * @return mixed
     */
    public function get(string $key, int $flags = 0)
    {
        switch ($key) {
            // strings/paths with env var and {$refs} support
            case 'vendor-dir':
            case 'bin-dir':
            case 'process-timeout':
            case 'data-dir':
            case 'cache-dir':
            case 'cache-files-dir':
            case 'cache-repo-dir':
            case 'cache-vcs-dir':
            case 'cafile':
            case 'capath':
                // convert foo-bar to COMPOSER_FOO_BAR and check if it exists since it overrides the local config
                $env = 'COMPOSER_' . strtoupper(strtr($key, '-', '_'));

                $val = $this->getComposerEnv($env);
                if ($val !== false) {
                    $this->setSourceOfConfigValue($val, $key, $env);
                }

                if ($key === 'process-timeout') {
                    return max(0, false !== $val ? (int) $val : $this->config[$key]);
                }

                $val = rtrim((string) $this->process(false !== $val ? $val : $this->config[$key], $flags), '/\\');
                $val = Platform::expandPath($val);

                if (substr($key, -4) !== '-dir') {
                    return $val;
                }

                return (($flags & self::RELATIVE_PATHS) == self::RELATIVE_PATHS) ? $val : $this->realpath($val);

            // booleans with env var support
            case 'cache-read-only':
            case 'htaccess-protect':
                // convert foo-bar to COMPOSER_FOO_BAR and check if it exists since it overrides the local config
                $env = 'COMPOSER_' . strtoupper(strtr($key, '-', '_'));

                $val = $this->getComposerEnv($env);
                if (false === $val) {
                    $val = $this->config[$key];
                } else {
                    $this->setSourceOfConfigValue($val, $key, $env);
                }

                return $val !== 'false' && (bool) $val;

            // booleans without env var support
            case 'disable-tls':
            case 'secure-http':
            case 'use-github-api':
            case 'lock':
                // special case for secure-http
                if ($key === 'secure-http' && $this->get('disable-tls') === true) {
                    return false;
                }

                return $this->config[$key] !== 'false' && (bool) $this->config[$key];

            // ints without env var support
            case 'cache-ttl':
                return max(0, (int) $this->config[$key]);

            // numbers with kb/mb/gb support, without env var support
            case 'cache-files-maxsize':
                if (!Preg::isMatch('/^\s*([0-9.]+)\s*(?:([kmg])(?:i?b)?)?\s*$/i', (string) $this->config[$key], $matches)) {
                    throw new \RuntimeException(
                        "Could not parse the value of '$key': {$this->config[$key]}"
                    );
                }
                $size = (float) $matches[1];
                if (isset($matches[2])) {
                    switch (strtolower($matches[2])) {
                        case 'g':
                            $size *= 1024;
                            // intentional fallthrough
                            // no break
                        case 'm':
                            $size *= 1024;
                            // intentional fallthrough
                            // no break
                        case 'k':
                            $size *= 1024;
                            break;
                    }
                }

                return max(0, (int) $size);

            // special cases below
            case 'cache-files-ttl':
                if (isset($this->config[$key])) {
                    return max(0, (int) $this->config[$key]);
                }

                return $this->get('cache-ttl');

            case 'home':
                return rtrim($this->process(Platform::expandPath($this->config[$key]), $flags), '/\\');

            case 'bin-compat':
                $value = $this->getComposerEnv('COMPOSER_BIN_COMPAT') ?: $this->config[$key];

                if (!in_array($value, array('auto', 'full', 'proxy', 'symlink'))) {
                    throw new \RuntimeException(
                        "Invalid value for 'bin-compat': {$value}. Expected auto, full or proxy"
                    );
                }

                if ($value === 'symlink') {
                    @trigger_error('config.bin-compat "symlink" is deprecated since Composer 2.2, use auto, full (for Windows compatibility) or proxy instead.', E_USER_DEPRECATED);
                }

                return $value;

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
                $protos = $this->config['github-protocols'];
                if ($this->config['secure-http'] && false !== ($index = array_search('git', $protos))) {
                    unset($protos[$index]);
                }
                if (reset($protos) === 'http') {
                    throw new \RuntimeException('The http protocol for github is not available anymore, update your config\'s github-protocols to use "https", "git" or "ssh"');
                }

                return $protos;

            case 'autoloader-suffix':
                if ($this->config[$key] === '') { // we need to guarantee null or non-empty-string
                    return null;
                }

                return $this->process($this->config[$key], $flags);

            default:
                if (!isset($this->config[$key])) {
                    return null;
                }

                return $this->process($this->config[$key], $flags);
        }
    }

    /**
     * @param int $flags
     *
     * @return array<string, mixed[]>
     */
    public function all(int $flags = 0): array
    {
        $all = array(
            'repositories' => $this->getRepositories(),
        );
        foreach (array_keys($this->config) as $key) {
            $all['config'][$key] = $this->get($key, $flags);
        }

        return $all;
    }

    /**
     * @param string $key
     * @return string
     */
    public function getSourceOfValue(string $key): string
    {
        $this->get($key);

        return $this->sourceOfConfigValue[$key] ?? self::SOURCE_UNKNOWN;
    }

    /**
     * @param mixed  $configValue
     * @param string $path
     * @param string $source
     *
     * @return void
     */
    private function setSourceOfConfigValue($configValue, string $path, string $source): void
    {
        $this->sourceOfConfigValue[$path] = $source;

        if (is_array($configValue)) {
            foreach ($configValue as $key => $value) {
                $this->setSourceOfConfigValue($value, $path . '.' . $key, $source);
            }
        }
    }

    /**
     * @return array<string, mixed[]>
     */
    public function raw(): array
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
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    /**
     * Replaces {$refs} inside a config string
     *
     * @param  string|mixed $value a config string that can contain {$refs-to-other-config}
     * @param  int          $flags Options (see class constants)
     *
     * @return string|mixed
     */
    private function process($value, int $flags)
    {
        if (!is_string($value)) {
            return $value;
        }

        return Preg::replaceCallback('#\{\$(.+)\}#', function ($match) use ($flags) {
            return $this->get($match[1], $flags);
        }, $value);
    }

    /**
     * Turns relative paths in absolute paths without realpath()
     *
     * Since the dirs might not exist yet we can not call realpath or it will fail.
     *
     * @param  string $path
     * @return string
     */
    private function realpath(string $path): string
    {
        if (Preg::isMatch('{^(?:/|[a-z]:|[a-z0-9.]+://|\\\\\\\\)}i', $path)) {
            return $path;
        }

        return $this->baseDir ? $this->baseDir . '/' . $path : $path;
    }

    /**
     * Reads the value of a Composer environment variable
     *
     * This should be used to read COMPOSER_ environment variables
     * that overload config values.
     *
     * @param  string      $var
     * @return string|bool
     */
    private function getComposerEnv(string $var)
    {
        if ($this->useEnvironment) {
            return Platform::getEnv($var);
        }

        return false;
    }

    /**
     * @param string $name
     *
     * @return void
     */
    private function disableRepoByName(string $name): void
    {
        if (isset($this->repositories[$name])) {
            unset($this->repositories[$name]);
        } elseif ($name === 'packagist') { // BC support for default "packagist" named repo
            unset($this->repositories['packagist.org']);
        }
    }

    /**
     * Validates that the passed URL is allowed to be used by current config, or throws an exception.
     *
     * @param string      $url
     * @param IOInterface $io
     * @param mixed[]     $repoOptions
     *
     * @return void
     */
    public function prohibitUrlByConfig(string $url, IOInterface $io = null, array $repoOptions = []): void
    {
        // Return right away if the URL is malformed or custom (see issue #5173)
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        // Extract scheme and throw exception on known insecure protocols
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $hostname = parse_url($url, PHP_URL_HOST);
        if (in_array($scheme, array('http', 'git', 'ftp', 'svn'))) {
            if ($this->get('secure-http')) {
                if ($scheme === 'svn') {
                    if (in_array($hostname, $this->get('secure-svn-domains'), true)) {
                        return;
                    }

                    throw new TransportException("Your configuration does not allow connections to $url. See https://getcomposer.org/doc/06-config.md#secure-svn-domains for details.");
                }

                throw new TransportException("Your configuration does not allow connections to $url. See https://getcomposer.org/doc/06-config.md#secure-http for details.");
            }
            if ($io  !== null) {
                if (is_string($hostname)) {
                    if (!isset($this->warnedHosts[$hostname])) {
                        $io->writeError("<warning>Warning: Accessing $hostname over $scheme which is an insecure protocol.</warning>");
                    }
                    $this->warnedHosts[$hostname] = true;
                }
            }
        }

        if ($io !== null && is_string($hostname) && !isset($this->sslVerifyWarnedHosts[$hostname])) {
            $warning = null;
            if (isset($repoOptions['ssl']['verify_peer']) && !(bool) $repoOptions['ssl']['verify_peer']) {
                $warning = 'verify_peer';
            }

            if (isset($repoOptions['ssl']['verify_peer_name']) && !(bool) $repoOptions['ssl']['verify_peer_name']) {
                $warning = $warning === null ? 'verify_peer_name' : $warning . ' and verify_peer_name';
            }

            if ($warning !== null) {
                $io->writeError("<warning>Warning: Accessing $hostname with $warning disabled.</warning>");
                $this->sslVerifyWarnedHosts[$hostname] = true;
            }
        }
    }

    /**
     * Used by long-running custom scripts in composer.json
     *
     * "scripts": {
     *   "watch": [
     *     "Composer\\Config::disableProcessTimeout",
     *     "vendor/bin/long-running-script --watch"
     *   ]
     * }
     *
     * @return void
     */
    public static function disableProcessTimeout(): void
    {
        // Override global timeout set earlier by environment or config
        ProcessExecutor::setTimeout(0);
    }
}
