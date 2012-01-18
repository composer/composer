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
     * @param array $options Options to merge with the default
     * @param array $params  Parameters to specify on the context
     * @return resource Default context
     * @throws \RuntimeException if https proxy required and OpenSSL uninstalled
     */
    static public function getContext(array $options = array(), array $params = array())
    {
        $options = array_merge(array('http' => array()), $options);

        // Handle system proxy
        if (isset($_SERVER['HTTP_PROXY']) || isset($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = isset($_SERVER['HTTP_PROXY']) ? $_SERVER['HTTP_PROXY'] : $_SERVER['http_proxy'];
            
            // http(s):// is not supported in proxy
            $proxy = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxy);

            if (0 === strpos($proxy, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }
            
            $options['http'] = array(
                'proxy'           => $proxy,
                'request_fulluri' => true,
            );
        }
        
        return stream_context_create($options, $params);
    }
}