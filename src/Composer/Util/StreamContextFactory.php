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
     * @param  array             $defaultOptions Options to merge with the default
     * @param  array             $defaultParams  Parameters to specify on the context
     * @return resource          Default context
     * @throws \RuntimeException if https proxy required and OpenSSL uninstalled
     */
    public static function getContext(array $defaultOptions = array(), array $defaultParams = array())
    {
        $options = array('http' => array(
            // specify defaults again to try and work better with curlwrappers enabled
            'follow_location' => 1,
            'max_redirects' => 20,
        ));

        // Handle system proxy
        if (!empty($_SERVER['HTTP_PROXY']) || !empty($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = parse_url(!empty($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY']);
        }

        if (!empty($proxy)) {
            $proxyURL = isset($proxy['scheme']) ? $proxy['scheme'] . '://' : '';
            $proxyURL .= isset($proxy['host']) ? $proxy['host'] : '';

            if (isset($proxy['port'])) {
                $proxyURL .= ":" . $proxy['port'];
            } elseif ('http://' == substr($proxyURL, 0, 7)) {
                $proxyURL .= ":80";
            } elseif ('https://' == substr($proxyURL, 0, 8)) {
                $proxyURL .= ":443";
            }

            // http(s):// is not supported in proxy
            $proxyURL = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxyURL);

            if (0 === strpos($proxyURL, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $options['http']['proxy'] = $proxyURL;

            // enabled request_fulluri unless it is explicitly disabled
            $reqFullUriEnv = getenv('HTTP_PROXY_REQUEST_FULLURI');
            if ($reqFullUriEnv === false || $reqFullUriEnv === '' || (strtolower($reqFullUriEnv) !== 'false' && (bool) $reqFullUriEnv)) {
                $options['http']['request_fulluri'] = true;
            }

            if (isset($proxy['user'])) {
                $auth = $proxy['user'];
                if (isset($proxy['pass'])) {
                    $auth .= ':' . $proxy['pass'];
                }
                $auth = base64_encode($auth);

                // Preserve headers if already set in default options
                if (isset($defaultOptions['http']['header'])) {
                    if (is_string($defaultOptions['http']['header'])) {
                        $defaultOptions['http']['header'] = array($defaultOptions['http']['header']);
                    }
                    $defaultOptions['http']['header'][] = "Proxy-Authorization: Basic {$auth}";
                } else {
                    $options['http']['header'] = array("Proxy-Authorization: Basic {$auth}");
                }
            }
        }

        $options = array_replace_recursive($options, $defaultOptions);

        if (isset($options['http']['header'])) {
            $options['http']['header'] = self::fixHttpHeaderField($options['http']['header']);
        }

        return stream_context_create($options, $defaultParams);
    }

    /**
     * A bug in PHP prevents the headers from correctly beeing sent when a content-type header is present and
     * NOT at the end of the array
     *
     * This method fixes the array by moving the content-type header to the end
     *
     * @link https://bugs.php.net/bug.php?id=61548
     * @param $header
     * @return array
     */
    private static function fixHttpHeaderField($header)
    {
        if (!is_array($header)) {
            $header = explode("\r\n", $header);
        }
        uasort($header, function ($el) {
            return preg_match('{^content-type}i', $el) ? 1 : -1;
        });

        return $header;
    }
}
