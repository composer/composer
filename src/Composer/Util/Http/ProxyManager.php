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

namespace Composer\Util\Http;

use Composer\Downloader\TransportException;
use Composer\Util\NoProxyPattern;

/**
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class ProxyManager
{
    /** @var ?string */
    private $error = null;
    /** @var ?ProxyItem */
    private $httpProxy = null;
    /** @var ?ProxyItem */
    private $httpsProxy = null;
    /** @var ?NoProxyPattern */
    private $noProxyHandler = null;

    /** @var ?self */
    private static $instance = null;

    private function __construct()
    {
        try {
            $this->getProxyData();
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public static function getInstance(): ProxyManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Clears the persistent instance
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function hasProxy(): bool
    {
        return $this->httpProxy !== null || $this->httpsProxy !== null;
    }

    /**
     * Returns a RequestProxy instance for the request url
     *
     * @param non-empty-string $requestUrl
     */
    public function getProxyForRequest(string $requestUrl): RequestProxy
    {
        if ($this->error !== null) {
            throw new TransportException('Unable to use a proxy: '.$this->error);
        }

        $scheme = (string) parse_url($requestUrl, PHP_URL_SCHEME);
        $proxy = $this->getProxyForScheme($scheme);

        if ($proxy === null) {
            return RequestProxy::none();
        }

        if ($this->noProxy($requestUrl)) {
            return RequestProxy::noProxy();
        }

        return $proxy->toRequestProxy($scheme);
    }

    /**
     * Returns a ProxyItem if one is set for the scheme, otherwise null
     */
    private function getProxyForScheme(string $scheme): ?ProxyItem
    {
        if ($scheme === 'http') {
            return $this->httpProxy;
        }

        if ($scheme === 'https') {
            return $this->httpsProxy;
        }

        return null;
    }

    /**
     * Finds proxy values from the environment and sets class properties
     */
    private function getProxyData(): void
    {
        // Handle http_proxy/HTTP_PROXY on CLI only for security reasons
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            [$env, $name] = $this->getProxyEnv('http_proxy');
            if ($env !== null) {
                $this->httpProxy = new ProxyItem($env, $name);
            }
        }

        // Handle cgi_http_proxy/CGI_HTTP_PROXY if needed
        if ($this->httpProxy === null) {
            [$env, $name] = $this->getProxyEnv('cgi_http_proxy');
            if ($env !== null) {
                $this->httpProxy = new ProxyItem($env, $name);
            }
        }

        // Handle https_proxy/HTTPS_PROXY
        [$env, $name] = $this->getProxyEnv('https_proxy');
        if ($env !== null) {
            $this->httpsProxy = new ProxyItem($env, $name);
        }

        // Handle no_proxy/NO_PROXY
        [$env, $name] = $this->getProxyEnv('no_proxy');
        if ($env !== null) {
            $this->noProxyHandler = new NoProxyPattern($env);
        }
    }

    /**
     * Searches $_SERVER for case-sensitive values
     *
     * @return array{0: string|null, 1: string} value, name
     */
    private function getProxyEnv(string $envName): array
    {
        $names = [strtolower($envName), strtoupper($envName)];

        foreach ($names as $name) {
            if (is_string($_SERVER[$name] ?? null)) {
                if ($_SERVER[$name] !== '') {
                    return [$_SERVER[$name], $name];
                }
            }
        }

        return [null, ''];
    }

    /**
     * Returns true if a url matches no_proxy value
     */
    private function noProxy(string $requestUrl): bool
    {
        if ($this->noProxyHandler === null) {
            return false;
        }

        return $this->noProxyHandler->test($requestUrl);
    }
}
