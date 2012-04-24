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

use Composer\Downloader\TransportException;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;

/**
 * A driver implementation for driver with authorization interaction.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class VcsDriver implements VcsDriverInterface
{
    protected $url;
    protected $io;
    protected $config;
    protected $process;
    protected $remoteFilesystem;

    /**
     * Constructor.
     *
     * @param string      $url The URL
     * @param IOInterface $io  The IO instance
     * @param Config      $config The composer configuration
     * @param ProcessExecutor $process  Process instance, injectable for mocking
     * @param callable $remoteFilesystem Remote Filesystem, injectable for mocking
     */
    final public function __construct($url, IOInterface $io, Config $config, ProcessExecutor $process = null, $remoteFilesystem = null)
    {
        $this->url = $url;
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor;
        $this->remoteFilesystem = $remoteFilesystem ?: new RemoteFilesystem($io);
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        try {
            return (Boolean) $this->getComposerInformation($identifier);
        } catch (TransportException $e) {
        }

        return false;
    }

    /**
     * Get the https or http protocol depending on SSL support.
     *
     * Call this only if you know that the server supports both.
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

    /**
     * Get the remote content.
     *
     * @param string $url The URL of content
     *
     * @return mixed The result
     */
    protected function getContents($url)
    {
        return $this->remoteFilesystem->getContents($this->url, $url, false);
    }

    protected static function isLocalUrl($url)
    {
        return (Boolean) preg_match('{^(file://|/|[a-z]:[\\\\/])}i', $url);
    }
}
