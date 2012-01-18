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
    private static $context;

    /**
     * Creates a context supporting HTTP proxies
     *
     * @return resource Default context
     * @throws \RuntimeException if https proxy required and OpenSSL uninstalled
     */
    public static function getContext()
    {
        if (null !== self::$context) {
            return self::$context;
        }
        
        // Handle system proxy
        $params = array('http' => array());

        if (isset($_SERVER['HTTP_PROXY']) || isset($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = isset($_SERVER['HTTP_PROXY']) ? $_SERVER['HTTP_PROXY'] : $_SERVER['http_proxy'];
            
            // http(s):// is not supported in proxy
            $proxy = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxy);

            if (0 === strpos($proxy, 'ssl:') && !extension_loaded('openssl')) {
                throw new \RuntimeException('You must enable the openssl extension to use a proxy over https');
            }
            
            $params['http'] = array(
                'proxy'           => $proxy,
                'request_fulluri' => true,
            );
        }
        
        return self::$context = stream_context_create($params);
    }
}