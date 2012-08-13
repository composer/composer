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
        $options = array('http' => array());

        // Handle system proxy
        if (!empty($_SERVER['HTTP_PROXY']) || !empty($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = parse_url(!empty($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY']);
        }

        if (!empty($proxy)) {
            $proxyURL = (isset($proxy['scheme']) ? $proxy['scheme'] : '') . '://';
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

            $options['http'] = array(
                'proxy'           => $proxyURL,
                'request_fulluri' => true,
            );

            if (isset($proxy['user'])) {
                $auth = $proxy['user'];
                if (isset($proxy['pass'])) {
                    $auth .= ':' . $proxy['pass'];
                }
                $auth = base64_encode($auth);

                // Preserve headers if already set in default options
                if (isset($defaultOptions['http']['header'])) {
                    $defaultOptions['http']['header'] .= "Proxy-Authorization: Basic {$auth}\r\n";
                } else {
                    $options['http']['header'] = "Proxy-Authorization: Basic {$auth}\r\n";
                }
            }
        }

        $options = array_replace_recursive($options, $defaultOptions);

        return stream_context_create($options, $defaultParams);
    }
}
