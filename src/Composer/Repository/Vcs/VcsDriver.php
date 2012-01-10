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

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

abstract class VcsDriver
{
    protected $url;
    protected $input;
    protected $output;

    /**
     * Constructor
     *
     * @param string          $url    The URL
     * @param InputInterface  $input  The Input instance
     * @param OutputInterface $output The output instance
     */
    public function __construct($url, InputInterface $input, OutputInterface $output)
    {
        $this->url = $url;
        $this->input = $input;
        $this->output = $output;
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
