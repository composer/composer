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
 * A driver implementation for driver with authentification interaction.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */

use Composer\Console\Helper\WrapperInterface;

abstract class VcsDriver
{
    protected $url;
    protected $wrapper;

    /**
     * Constructor.
     *
     * @param string           $url     The URL
     * @param WrapperInterface $wrapper The Wrapper instance
     */
    public function __construct($url, WrapperInterface $wrapper)
    {
        $this->url = $url;
        $this->wrapper = $wrapper;
    }

    /**
     * Get the https or http protocol.
     *
     * @return string The correct type of protocol
     */
    protected function getScheme()
    {
        if (extension_loaded('openssl')) {
            return 'https';
        }
        return 'http';
    }
}
