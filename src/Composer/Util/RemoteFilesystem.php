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

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Transfer\CurlDriver;
use Composer\Util\Transfer\StreamDriver;

/**
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 * @author Alexander Goryachev <mail@a-goryachev.ru>
 */
class RemoteFilesystem {

    /**
     * Transfer driver for non http protocols
     * @var \Composer\Util\Transfer\DriverInterface
     */
    private $localDriver;
    /**
     * Transfer driver for http protocols
     * @var \Composer\Util\Transfer\DriverInterface
     */
    private $httpDriver;
    /**
     * Currently used driver
     * @var \Composer\Util\Transfer\DriverInterface
     */
    private $driver;

    /**
     * Constructor.
     *
     * @param IOInterface $io      The IO instance
     * @param Config      $config  The config
     * @param array       $options The options
     */
    public function __construct(IOInterface $io, Config $config = null, array $options = array()) {
        /* Using cUrl, if exists, for http(s) connections */
        $this->httpDriver = function_exists('curl_version') ? new CurlDriver($io, $config, $options,$this) : new StreamDriver($io, $config, $options,$this);
        /* Using stream context wrapper for non http(s) connections */
        $this->localDriver = new StreamDriver($io, $config, $options,$this);
    }

    /**
     * Copy the remote file in local.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param string  $fileName  the local filename
     * @param boolean $progress  Display the progression
     * @param array   $options   Additional context options
     *
     * @return bool true
     */
    public function copy($originUrl, $fileUrl, $fileName, $progress = true, $options = array()) {
        if (preg_match('#^https?#i', $fileUrl)) {
            $this->driver = $this->httpDriver;
        } else {
            $this->driver = $this->localDriver;
        }
        return $this->driver->get($originUrl, $fileUrl, $options, $fileName, $progress);
    }

    /**
     * Get the content.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param boolean $progress  Display the progression
     * @param array   $options   Additional context options
     *
     * @return bool|string The content
     */
    public function getContents($originUrl, $fileUrl, $progress = true, $options = array()) {
        if (preg_match('#^https?#i', $fileUrl)) {
            $this->driver = $this->httpDriver;
        } else {
            $this->driver = $this->localDriver;
        }
        return $this->driver->get($originUrl, $fileUrl, $options, null, $progress);
    }

    /**
     * Retrieve the options set in the constructor
     *
     * @return array Options
     */
    public function getOptions() {
        return $this->driver->getOptions();
    }

    /**
     * Returns the headers of the last request
     *
     * @return array
     */
    public function getLastHeaders() {
        return $this->driver->getLastHeaders();
    }

}
