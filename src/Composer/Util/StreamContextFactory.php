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

namespace Composer\Util;

use Composer\Composer;
use Composer\CaBundle\CaBundle;
use Composer\Downloader\TransportException;
use Psr\Log\LoggerInterface;

/**
 * Allows the creation of a basic context supporting http proxy
 *
 * @author Jordan Alliot <jordan.alliot@gmail.com>
 * @author Markus Tacker <m@coderbyheart.de>
 */
final class StreamContextFactory
{
    /**
     * Creates a context supporting HTTP proxies
     *
     * @param  string            $url            URL the context is to be used for
     * @psalm-param array{http: array{follow_location?: int, max_redirects?: int, header?: string|array<string, string|int>}} $defaultOptions
     * @param  array             $defaultOptions Options to merge with the default
     * @param  array             $defaultParams  Parameters to specify on the context
     * @throws \RuntimeException if https proxy required and OpenSSL uninstalled
     * @return resource          Default context
     */
    public static function getContext($url, array $defaultOptions = array(), array $defaultParams = array())
    {
        $options = array('http' => array(
            // specify defaults again to try and work better with curlwrappers enabled
            'follow_location' => 1,
            'max_redirects' => 20,
        ));

        $options = array_replace_recursive($options, self::initOptions($url, $defaultOptions));
        unset($defaultOptions['http']['header']);
        $options = array_replace_recursive($options, $defaultOptions);

        if (isset($options['http']['header'])) {
            $options['http']['header'] = self::fixHttpHeaderField($options['http']['header']);
        }

        return stream_context_create($options, $defaultParams);
    }

    /**
     * @param string $url
     * @param array $options
     * @psalm-return array{http:{header: string[], proxy?: string, request_fulluri: bool}, ssl: array}
     * @return array formatted as a stream context array
     */
    public static function initOptions($url, array $options)
    {
        // Make sure the headers are in an array form
        if (!isset($options['http']['header'])) {
            $options['http']['header'] = array();
        }
        if (is_string($options['http']['header'])) {
            $options['http']['header'] = explode("\r\n", $options['http']['header']);
        }

        // Handle HTTP_PROXY/http_proxy on CLI only for security reasons
        if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && (!empty($_SERVER['HTTP_PROXY']) || !empty($_SERVER['http_proxy']))) {
            $proxy = parse_url(!empty($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY']);
        }

        // Prefer CGI_HTTP_PROXY if available
        if (!empty($_SERVER['CGI_HTTP_PROXY'])) {
            $proxy = parse_url($_SERVER['CGI_HTTP_PROXY']);
        }

        // Override with HTTPS proxy if present and URL is https
        if (preg_match('{^https://}i', $url) && (!empty($_SERVER['HTTPS_PROXY']) || !empty($_SERVER['https_proxy']))) {
            $proxy = parse_url(!empty($_SERVER['https_proxy']) ? $_SERVER['https_proxy'] : $_SERVER['HTTPS_PROXY']);
        }

        // Remove proxy if URL matches no_proxy directive
        if (!empty($_SERVER['NO_PROXY']) || !empty($_SERVER['no_proxy']) && parse_url($url, PHP_URL_HOST)) {
            $pattern = new NoProxyPattern(!empty($_SERVER['no_proxy']) ? $_SERVER['no_proxy'] : $_SERVER['NO_PROXY']);
            if ($pattern->test($url)) {
                unset($proxy);
            }
        }

        if (!empty($proxy)) {
            $proxyURL = isset($proxy['scheme']) ? $proxy['scheme'] . '://' : '';
            $proxyURL .= isset($proxy['host']) ? $proxy['host'] : '';

            if (isset($proxy['port'])) {
                $proxyURL .= ":" . $proxy['port'];
            } elseif (strpos($proxyURL, 'http://') === 0) {
                $proxyURL .= ":80";
            } elseif (strpos($proxyURL, 'https://') === 0) {
                $proxyURL .= ":443";
            }

            // http(s):// is not supported in proxy
            $proxyURL = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxyURL);

            if (0 === strpos($proxyURL, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $options['http']['proxy'] = $proxyURL;

            // enabled request_fulluri unless it is explicitly disabled
            switch (parse_url($url, PHP_URL_SCHEME)) {
                case 'http': // default request_fulluri to true for HTTP
                    $reqFullUriEnv = getenv('HTTP_PROXY_REQUEST_FULLURI');
                    if ($reqFullUriEnv === false || $reqFullUriEnv === '' || (strtolower($reqFullUriEnv) !== 'false' && (bool) $reqFullUriEnv)) {
                        $options['http']['request_fulluri'] = true;
                    }
                    break;
                case 'https': // default request_fulluri to false for HTTPS
                    $reqFullUriEnv = getenv('HTTPS_PROXY_REQUEST_FULLURI');
                    if (strtolower($reqFullUriEnv) !== 'false' && (bool) $reqFullUriEnv) {
                        $options['http']['request_fulluri'] = true;
                    }
                    break;
            }

            // add SNI opts for https URLs
            if ('https' === parse_url($url, PHP_URL_SCHEME)) {
                $options['ssl']['SNI_enabled'] = true;
                if (PHP_VERSION_ID < 50600) {
                    $options['ssl']['SNI_server_name'] = parse_url($url, PHP_URL_HOST);
                }
            }

            // handle proxy auth if present
            if (isset($proxy['user'])) {
                $auth = rawurldecode($proxy['user']);
                if (isset($proxy['pass'])) {
                    $auth .= ':' . rawurldecode($proxy['pass']);
                }
                $auth = base64_encode($auth);

                $options['http']['header'][] = "Proxy-Authorization: Basic {$auth}";
            }
        }

        if (defined('HHVM_VERSION')) {
            $phpVersion = 'HHVM ' . HHVM_VERSION;
        } else {
            $phpVersion = 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        }

        if (extension_loaded('curl')) {
            $curl = curl_version();
            $httpVersion = 'curl '.$curl['version'];
        } else {
            $httpVersion = 'streams';
        }

        if (!isset($options['http']['header']) || false === stripos(implode('', $options['http']['header']), 'user-agent')) {
            $options['http']['header'][] = sprintf(
                'User-Agent: Composer/%s (%s; %s; %s; %s%s)',
                Composer::getVersion(),
                function_exists('php_uname') ? php_uname('s') : 'Unknown',
                function_exists('php_uname') ? php_uname('r') : 'Unknown',
                $phpVersion,
                $httpVersion,
                getenv('CI') ? '; CI' : ''
            );
        }

        return $options;
    }

    /**
     * @param array $options
     *
     * @return array
     */
    public static function getTlsDefaults(array $options, LoggerInterface $logger = null)
    {
        $ciphers = implode(':', array(
            'ECDHE-RSA-AES128-GCM-SHA256',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'DHE-RSA-AES128-GCM-SHA256',
            'DHE-DSS-AES128-GCM-SHA256',
            'kEDH+AESGCM',
            'ECDHE-RSA-AES128-SHA256',
            'ECDHE-ECDSA-AES128-SHA256',
            'ECDHE-RSA-AES128-SHA',
            'ECDHE-ECDSA-AES128-SHA',
            'ECDHE-RSA-AES256-SHA384',
            'ECDHE-ECDSA-AES256-SHA384',
            'ECDHE-RSA-AES256-SHA',
            'ECDHE-ECDSA-AES256-SHA',
            'DHE-RSA-AES128-SHA256',
            'DHE-RSA-AES128-SHA',
            'DHE-DSS-AES128-SHA256',
            'DHE-RSA-AES256-SHA256',
            'DHE-DSS-AES256-SHA',
            'DHE-RSA-AES256-SHA',
            'AES128-GCM-SHA256',
            'AES256-GCM-SHA384',
            'AES128-SHA256',
            'AES256-SHA256',
            'AES128-SHA',
            'AES256-SHA',
            'AES',
            'CAMELLIA',
            'DES-CBC3-SHA',
            '!aNULL',
            '!eNULL',
            '!EXPORT',
            '!DES',
            '!RC4',
            '!MD5',
            '!PSK',
            '!aECDH',
            '!EDH-DSS-DES-CBC3-SHA',
            '!EDH-RSA-DES-CBC3-SHA',
            '!KRB5-DES-CBC3-SHA',
        ));

        /**
         * CN_match and SNI_server_name are only known once a URL is passed.
         * They will be set in the getOptionsForUrl() method which receives a URL.
         *
         * cafile or capath can be overridden by passing in those options to constructor.
         */
        $defaults = array(
            'ssl' => array(
                'ciphers' => $ciphers,
                'verify_peer' => true,
                'verify_depth' => 7,
                'SNI_enabled' => true,
                'capture_peer_cert' => true,
            ),
        );

        if (isset($options['ssl'])) {
            $defaults['ssl'] = array_replace_recursive($defaults['ssl'], $options['ssl']);
        }

        /**
         * Attempt to find a local cafile or throw an exception if none pre-set
         * The user may go download one if this occurs.
         */
        if (!isset($defaults['ssl']['cafile']) && !isset($defaults['ssl']['capath'])) {
            $result = CaBundle::getSystemCaRootBundlePath($logger);

            if (is_dir($result)) {
                $defaults['ssl']['capath'] = $result;
            } else {
                $defaults['ssl']['cafile'] = $result;
            }
        }

        if (isset($defaults['ssl']['cafile']) && (!is_readable($defaults['ssl']['cafile']) || !CaBundle::validateCaFile($defaults['ssl']['cafile'], $logger))) {
            throw new TransportException('The configured cafile was not valid or could not be read.');
        }

        if (isset($defaults['ssl']['capath']) && (!is_dir($defaults['ssl']['capath']) || !is_readable($defaults['ssl']['capath']))) {
            throw new TransportException('The configured capath was not valid or could not be read.');
        }

        /**
         * Disable TLS compression to prevent CRIME attacks where supported.
         */
        if (PHP_VERSION_ID >= 50413) {
            $defaults['ssl']['disable_compression'] = true;
        }

        return $defaults;
    }

    /**
     * A bug in PHP prevents the headers from correctly being sent when a content-type header is present and
     * NOT at the end of the array
     *
     * This method fixes the array by moving the content-type header to the end
     *
     * @link https://bugs.php.net/bug.php?id=61548
     * @param string|array $header
     * @return array
     */
    private static function fixHttpHeaderField($header)
    {
        if (!is_array($header)) {
            $header = explode("\r\n", $header);
        }
        uasort($header, function ($el) {
            return stripos($el, 'content-type') === 0 ? 1 : -1;
        });

        return $header;
    }
}
