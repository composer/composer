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

namespace Composer\Util\Http;

use Composer\Downloader\TransportException;
use Composer\Util\NoProxyPattern;
use Composer\Util\Url;

/**
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class ProxyManager
{
    private $error;
    private $fullProxy;
    private $safeProxy;
    private $streams;
    private $hasProxy;
    private $info;
    private $lastProxy;
    /** @var NoProxyPattern */
    private $noProxyHandler;

    /** @var ProxyManager */
    private static $instance;

    private function __construct()
    {
        $this->fullProxy = $this->safeProxy = array(
            'http' => null,
            'https' => null,
        );

        $this->streams['http'] = $this->streams['https'] = array(
            'options' => null,
        );

        $this->hasProxy = false;
        $this->initProxyData();
    }

    /**
     * @return ProxyManager
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Clears the persistent instance
     */
    public static function reset()
    {
        self::$instance = null;
    }

    /**
     * Returns a RequestProxy instance for the request url
     *
     * @param  string       $requestUrl
     * @return RequestProxy
     */
    public function getProxyForRequest($requestUrl)
    {
        if ($this->error) {
            throw new TransportException('Unable to use a proxy: '.$this->error);
        }

        $scheme = parse_url($requestUrl, PHP_URL_SCHEME) ?: 'http';
        $proxyUrl = '';
        $options = array();
        $formattedProxyUrl = '';

        if ($this->hasProxy && in_array($scheme, array('http', 'https'), true) && $this->fullProxy[$scheme]) {
            if ($this->noProxy($requestUrl)) {
                $formattedProxyUrl = 'excluded by no_proxy';
            } else {
                $proxyUrl = $this->fullProxy[$scheme];
                $options = $this->streams[$scheme]['options'];
                ProxyHelper::setRequestFullUri($requestUrl, $options);
                $formattedProxyUrl = $this->safeProxy[$scheme];
            }
        }

        return new RequestProxy($proxyUrl, $options, $formattedProxyUrl);
    }

    /**
     * Returns true if a proxy is being used
     *
     * @return bool If false any error will be in $message
     */
    public function isProxying()
    {
        return $this->hasProxy;
    }

    /**
     * Returns proxy configuration info which can be shown to the user
     *
     * @return string|null Safe proxy URL or an error message if setting up proxy failed or null if no proxy was configured
     */
    public function getFormattedProxy()
    {
        return $this->hasProxy ? $this->info : $this->error;
    }

    /**
     * Initializes proxy values from the environment
     */
    private function initProxyData()
    {
        try {
            list($httpProxy, $httpsProxy, $noProxy) = ProxyHelper::getProxyData();
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $info = array();

        if ($httpProxy) {
            $info[] = $this->setData($httpProxy, 'http');
        }
        if ($httpsProxy) {
            $info[] = $this->setData($httpsProxy, 'https');
        }
        if ($this->hasProxy) {
            $this->info = implode(', ', $info);
            if ($noProxy) {
                $this->noProxyHandler = array(new NoProxyPattern($noProxy), 'test');
            }
        }
    }

    /**
     * Sets initial data
     *
     * @param string $url    Proxy url
     * @param string $scheme Environment variable scheme
     */
    private function setData($url, $scheme)
    {
        $safeProxy = Url::sanitize($url);
        $this->fullProxy[$scheme] = $url;
        $this->safeProxy[$scheme] = $safeProxy;
        $this->streams[$scheme]['options'] = ProxyHelper::getContextOptions($url);
        $this->hasProxy = true;

        return sprintf('%s=%s', $scheme, $safeProxy);
    }

    /**
     * Returns true if a url matches no_proxy value
     *
     * @param  string $requestUrl
     * @return bool
     */
    private function noProxy($requestUrl)
    {
        if ($this->noProxyHandler) {
            if (call_user_func($this->noProxyHandler, $requestUrl)) {
                $this->lastProxy = 'excluded by no_proxy';

                return true;
            }
        }

        return false;
    }
}
