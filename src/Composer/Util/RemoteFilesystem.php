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

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;

/**
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class RemoteFilesystem
{
    private $driver;

    /**
     * Constructor.
     *
     * @param IOInterface $io      The IO instance
     * @param array       $options The options
     */
    public function __construct(IOInterface $io, $options = array(), $driver = 'curl')
    {
           switch ($driver) {
               case 'curl':
                   if (extension_loaded('curl')) {
                       $this->driver = new CurlDriver($io, $options);
                       break;
                   }
               default:
                   $this->driver = new StreamDriver($io, $options);
           }
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
    public function copy($originUrl, $fileUrl, $fileName, $progress = true, $options = array())
    {
        $result = $this->driver->get($originUrl, $fileUrl, $options, $progress);
        // handle copy command if download was successful
        if (false !== $result && null !== $fileName) {
            $errorMessage = '';
            set_error_handler(function ($code, $msg) use (&$errorMessage) {
                if ($errorMessage) {
                    $errorMessage .= "\n";
                }
                $errorMessage .= preg_replace('{^file_put_contents\(.*?\): }', '', $msg);
            });
            $result = (bool) file_put_contents($fileName, $result);
            restore_error_handler();
            if (false === $result) {
                throw new TransportException('The "'.$fileUrl.'" file could not be written to '.$fileName.': '.$errorMessage);
            }
        }
        return $result;
    }

    /**
     * Get the content.
     *
     * @param string  $originUrl The origin URL
     * @param string  $fileUrl   The file URL
     * @param boolean $progress  Display the progression
     * @param array   $options   Additional context options
     *
     * @return string The content
     */
    public function getContents($originUrl, $fileUrl, $progress = true, $options = array())
    {
        return $this->driver->get($originUrl, $fileUrl, $options, $progress);
    }

}
