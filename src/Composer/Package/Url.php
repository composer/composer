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

namespace Composer\Package;

/**
 * Package URL, based on git-clone URLs
 */
class Url
{
    /**
     * @var string
     */
    private $url;

    /**
     * @param string $url
     * @return Url
     */
    public static function create($url)
    {
        return new Url($url);
    }

    /**
     * Url constructor.
     *
     * @param string $url
     */
    public function __construct($url)
    {
        $this->url = (string)$url;
    }

    /**
     * @return string
     */
    public function getProtocol()
    {
        $url = $this->url;
        if (strlen($scheme = $this->scheme($url))) {
            return $scheme;
        }

        # alternative scp-like syntax
        $slashPos = strpos($url, '/');
        $colonPos = strpos($url, ':');
        if (false !== $colonPos && ($slashPos === false || $slashPos > $colonPos)) {
            return 'ssh';
        }

        return 'file';
    }

    /**
     * @param string $url
     * @return null|string
     */
    private function scheme($url)
    {
        $result = preg_match('~^([a-zA-Z](?:[a-zA-Z0-9.+-])*)://~', $url, $matches);
        if (false === $result) {
            throw new \RuntimeException('Regex failed');
        }

        return $result ? strtolower($matches[1]) : null;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->url;
    }
}
