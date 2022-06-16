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

/**
 * Proxy discovery and helper class
 *
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class ProxyHelper
{
    /**
     * Returns proxy environment values
     *
     * @return array{string|null, string|null, string|null} httpProxy, httpsProxy, noProxy values
     *
     * @throws \RuntimeException on malformed url
     */
    public static function getProxyData(): array
    {
        $httpProxy = null;
        $httpsProxy = null;

        // Handle http_proxy/HTTP_PROXY on CLI only for security reasons
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            if ($env = self::getProxyEnv(array('http_proxy', 'HTTP_PROXY'), $name)) {
                $httpProxy = self::checkProxy($env, $name);
            }
        }

        // Prefer CGI_HTTP_PROXY if available
        if ($env = self::getProxyEnv(array('CGI_HTTP_PROXY'), $name)) {
            $httpProxy = self::checkProxy($env, $name);
        }

        // Handle https_proxy/HTTPS_PROXY
        if ($env = self::getProxyEnv(array('https_proxy', 'HTTPS_PROXY'), $name)) {
            $httpsProxy = self::checkProxy($env, $name);
        } else {
            $httpsProxy = $httpProxy;
        }

        // Handle no_proxy
        $noProxy = self::getProxyEnv(array('no_proxy', 'NO_PROXY'), $name);

        return array($httpProxy, $httpsProxy, $noProxy);
    }

    /**
     * Returns http context options for the proxy url
     *
     * @param string $proxyUrl
     *
     * @return array{http: array{proxy: string, header?: string}}
     */
    public static function getContextOptions(string $proxyUrl): array
    {
        $proxy = parse_url($proxyUrl);

        // Remove any authorization
        $proxyUrl = self::formatParsedUrl($proxy, false);
        $proxyUrl = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxyUrl);

        $options['http']['proxy'] = $proxyUrl;

        // Handle any authorization
        if (isset($proxy['user'])) {
            $auth = rawurldecode($proxy['user']);

            if (isset($proxy['pass'])) {
                $auth .= ':' . rawurldecode($proxy['pass']);
            }
            $auth = base64_encode($auth);
            // Set header as a string
            $options['http']['header'] = "Proxy-Authorization: Basic {$auth}";
        }

        return $options;
    }

    /**
     * Sets/unsets request_fulluri value in http context options array
     *
     * @param string  $requestUrl
     * @param mixed[] $options Set by method
     *
     * @return void
     */
    public static function setRequestFullUri(string $requestUrl, array &$options): void
    {
        if ('http' === parse_url($requestUrl, PHP_URL_SCHEME)) {
            $options['http']['request_fulluri'] = true;
        } else {
            unset($options['http']['request_fulluri']);
        }
    }

    /**
     * Searches $_SERVER for case-sensitive values
     *
     * @param string[]    $names Names to search for
     * @param string|null $name  Name of any found value
     *
     * @return string|null The found value
     */
    private static function getProxyEnv(array $names, ?string &$name): ?string
    {
        foreach ($names as $name) {
            if (!empty($_SERVER[$name])) {
                return $_SERVER[$name];
            }
        }

        return null;
    }

    /**
     * Checks and formats a proxy url from the environment
     *
     * @param  string            $proxyUrl
     * @param  string            $envName
     * @throws \RuntimeException on malformed url
     * @return string            The formatted proxy url
     */
    private static function checkProxy(string $proxyUrl, string $envName): string
    {
        $error = sprintf('malformed %s url', $envName);
        $proxy = parse_url($proxyUrl);

        // We need parse_url to have identified a host
        if (!isset($proxy['host'])) {
            throw new \RuntimeException($error);
        }

        $proxyUrl = self::formatParsedUrl($proxy, true);

        // We need a port because streams and curl use different defaults
        if (!parse_url($proxyUrl, PHP_URL_PORT)) {
            throw new \RuntimeException($error);
        }

        return $proxyUrl;
    }

    /**
     * Formats a url from its component parts
     *
     * @param  array{scheme?: string, host: string, port?: int, user?: string, pass?: string} $proxy
     * @param  bool                                                                           $includeAuth
     *
     * @return string The formatted value
     */
    private static function formatParsedUrl(array $proxy, bool $includeAuth): string
    {
        $proxyUrl = isset($proxy['scheme']) ? strtolower($proxy['scheme']) . '://' : '';

        if ($includeAuth && isset($proxy['user'])) {
            $proxyUrl .= $proxy['user'];

            if (isset($proxy['pass'])) {
                $proxyUrl .= ':' . $proxy['pass'];
            }
            $proxyUrl .= '@';
        }

        $proxyUrl .= $proxy['host'];

        if (isset($proxy['port'])) {
            $proxyUrl .= ':' . $proxy['port'];
        } elseif (str_starts_with($proxyUrl, 'http://')  ) {
            $proxyUrl .= ':80';
        } elseif (str_starts_with($proxyUrl, 'https://')  ) {
            $proxyUrl .= ':443';
        }

        return $proxyUrl;
    }
}
