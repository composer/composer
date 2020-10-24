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

use Composer\Util\Url;

/**
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class RequestProxy
{
    private $contextOptions;
    private $isSecure;
    private $lastProxy;
    private $safeUrl;
    private $url;

    /**
     * @param string $url
     * @param array $contextOptions
     * @param string $lastProxy
     */
    public function __construct($url, array $contextOptions, $lastProxy)
    {
        $this->url = $url;
        $this->contextOptions = $contextOptions;
        $this->lastProxy = $lastProxy;
        $this->safeUrl = Url::sanitize($url);
        $this->isSecure = 0 === strpos($url, 'https://');
    }

    /**
     * Returns an array of context options
     *
     * @return array
     */
    public function getContextOptions()
    {
        return $this->contextOptions;
    }

    /**
     * Returns the safe proxy url from the last request
     *
     * @param string|null $format Output format specifier
     * @return string Safe proxy, no proxy or empty
     */
    public function getLastProxy($format = '')
    {
        $result = '';
        if ($this->lastProxy) {
            $format = $format ?: '%s';
            $result = sprintf($format, $this->lastProxy);
        }

        return $result;
    }

    /**
     * Returns the proxy url
     *
     * @return string Proxy url or empty
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Returns true if this is a secure-proxy
     *
     * @return bool False if not secure or there is no proxy
     */
    public function isSecure()
    {
        return $this->isSecure;
    }
}
