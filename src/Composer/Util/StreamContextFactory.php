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

            // http(s):// is not supported in proxy
            if ('http://' == substr($proxy, 0, 7)) {
                $proxy = 'tcp://' . rtrim(substr($proxy, 7), '/') . (parse_url($proxy, PHP_URL_PORT) ? '' : ':80');
            } else if ('https://' == substr($proxy, 0, 8)) {
                $proxy = 'ssl://' . rtrim(substr($proxy, 8), '/') . (parse_url($proxy, PHP_URL_PORT) ? '' : ':443');
            }

            if (0 === strpos($proxy, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $options['http'] = array(
                'proxy'           => $proxy,
                'request_fulluri' => true,
            );
        }

        $options = array_merge_recursive($options, $defaultOptions);
        
        return stream_context_create($options, $defaultParams);
    }
}
