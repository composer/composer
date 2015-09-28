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
 * Tests URLs against no_proxy patterns.
 */
class NoProxyPattern
{
    /**
     * @var string[]
     */
    protected $rules = array();

    /**
     * @param string $pattern no_proxy pattern
     */
    public function __construct($pattern)
    {
        $this->rules = preg_split("/[\s,]+/", $pattern);
    }

    /**
     * Test a URL against the stored pattern.
     *
     * @param string $url
     *
     * @return true if the URL matches one of the rules.
     */
    public function test($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (empty($port)) {
            switch (parse_url($url, PHP_URL_SCHEME)) {
                case 'http':
                    $port = 80;
                    break;
                case 'https':
                    $port = 443;
                    break;
            }
        }

        foreach ($this->rules as $rule) {
            if ($rule == '*') {
                return true;
            }

            $match = false;

            list($ruleHost) = explode(':', $rule);
            list($base) = explode('/', $ruleHost);

            if (filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                // ip or cidr match

                if (!isset($ip)) {
                    $ip = gethostbyname($host);
                }

                if (strpos($ruleHost, '/') === false) {
                    $match = $ip === $ruleHost;
                } else {
                    // gethostbyname() failed to resolve $host to an ip, so we assume
                    // it must be proxied to let the proxy's DNS resolve it
                    if ($ip === $host) {
                        $match = false;
                    } else {
                        // match resolved IP against the rule
                        $match = self::inCIDRBlock($ruleHost, $ip);
                    }
                }
            } else {
                // match end of domain

                $haystack = '.' . trim($host, '.') . '.';
                $needle = '.'. trim($ruleHost, '.') .'.';
                $match = stripos(strrev($haystack), strrev($needle)) === 0;
            }

            // final port check
            if ($match && strpos($rule, ':') !== false) {
                list(, $rulePort) = explode(':', $rule);
                if (!empty($rulePort) && $port != $rulePort) {
                    $match = false;
                }
            }

            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check an IP address against a CIDR
     *
     * http://framework.zend.com/svn/framework/extras/incubator/library/ZendX/Whois/Adapter/Cidr.php
     *
     * @param string $cidr IPv4 block in CIDR notation
     * @param string $ip   IPv4 address
     *
     * @return bool
     */
    private static function inCIDRBlock($cidr, $ip)
    {
        // Get the base and the bits from the CIDR
        list($base, $bits) = explode('/', $cidr);

        // Now split it up into it's classes
        list($a, $b, $c, $d) = explode('.', $base);

        // Now do some bit shifting/switching to convert to ints
        $i    = ($a << 24) + ($b << 16) + ($c << 8) + $d;
        $mask = $bits == 0 ? 0 : (~0 << (32 - $bits));

        // Here's our lowest int
        $low = $i & $mask;

        // Here's our highest int
        $high = $i | (~$mask & 0xFFFFFFFF);

        // Now split the ip we're checking against up into classes
        list($a, $b, $c, $d) = explode('.', $ip);

        // Now convert the ip we're checking against to an int
        $check = ($a << 24) + ($b << 16) + ($c << 8) + $d;

        // If the ip is within the range, including highest/lowest values,
        // then it's within the CIDR range
        return $check >= $low && $check <= $high;
    }
}
