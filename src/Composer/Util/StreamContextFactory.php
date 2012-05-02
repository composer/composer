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
     * @param array $defaultOptions Options to merge with the default
     * @param array $defaultParams  Parameters to specify on the context
     * @return resource Default context
     * @throws \RuntimeException if https proxy required and OpenSSL uninstalled
     */
    static public function getContext(array $defaultOptions = array(), array $defaultParams = array())
    {
        $options = array('http' => array());

        // Handle system proxy
        if (isset($_SERVER['HTTP_PROXY']) || isset($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = isset($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY'];
            
            $proxyURL = parse_url($proxy, PHP_URL_SCHEME) . "://";
            $proxyURL .= parse_url($proxy, PHP_URL_HOST);
            
            $proxyPort = parse_url($proxy, PHP_URL_PORT);
            
            if (isset($proxyPort)) {
                $proxyURL .= ":" . $proxyPort;
            } else {
                if ('http://' == substr($proxyURL, 0, 7)) {
                    $proxyURL .= ":80";
                } else if ('https://' == substr($proxyURL, 0, 8)) {
                    $proxyURL .= ":443";
                }
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
            
            // Extract authentication credentials from the proxy url
            $user = parse_url($proxy, PHP_URL_USER);
            $pass = parse_url($proxy, PHP_URL_PASS);
            
            if (isset($user)) {
                $auth = $user;
                if (isset($pass)) {
                    $auth .= ":{$pass}";
                }
                $auth = base64_encode($auth);
                
                // Preserve headers if already set in default options 
                if (isset($defaultOptions['http']) && isset($defaultOptions['http']['header'])) {
                    $defaultOptions['http']['header'] .=  "Proxy-Authorization: Basic {$auth}\r\n";
                } else {
                    $options['http']['header'] = "Proxy-Authorization: Basic {$auth}\r\n";
                }
            }
        }
        
        $options = array_merge_recursive($options, $defaultOptions);
        
        return stream_context_create($options, $defaultParams);
    }
}
