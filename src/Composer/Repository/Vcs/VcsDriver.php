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

namespace Composer\Repository\Vcs;

/**
 * A driver implementation
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class VcsDriver
{
    protected $url;

    /**
     * Constructor
     *
     * @param string $url The URL
     */
    public function __construct($url)
    {
        $this->url = $url;
    }

    /**
     * Get the https or http protocol.
     *
     * @return string The correct type of protocol
     */
    protected function getHttpSupport()
    {
        if (extension_loaded('openssl')) {
            return 'https';
        }
        return 'http';
    }
}
