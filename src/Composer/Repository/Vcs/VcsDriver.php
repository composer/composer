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
 * A driver implementation for driver with authentication interaction.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class VcsDriver implements VcsDriverInterface
{
    protected $url;
    protected $originUrl;
    protected $repoConfig;
    protected $io;
    protected $config;
    protected $process;
    protected $remoteFilesystem;

    /**
     * Constructor.
     *
     * @param array            $repoConfig       The repository configuration
     * @param IOInterface      $io               The IO instance
     * @param Config           $config           The composer configuration
     * @param ProcessExecutor  $process          Process instance, injectable for mocking
     * @param RemoteFilesystem $remoteFilesystem Remote Filesystem, injectable for mocking
     */
    final public function __construct(array $repoConfig, IOInterface $io, Config $config, ProcessExecutor $process = null, RemoteFilesystem $remoteFilesystem = null)
    {

        if (self::isLocalUrl($repoConfig['url'])) {
            $repoConfig['url'] = realpath(
                preg_replace('/^file:\/\//', '', $repoConfig['url'])
            );
        }

        $this->url = $repoConfig['url'];
        $this->originUrl = $repoConfig['url'];
        $this->repoConfig = $repoConfig;
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor($io);
        $this->remoteFilesystem = $remoteFilesystem ?: new RemoteFilesystem($io);
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        try {
            return (bool) $this->getComposerInformation($identifier);
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
        return $this->remoteFilesystem->getContents($this->originUrl, $url, false);
    }

    /**
     * Return if current repository url is local
     *
     * @param  string  $url
     * @return boolean Repository url is local
     */
    protected static function isLocalUrl($url)
    {
        return (bool) preg_match('{^(file://|/|[a-z]:[\\\\/])}i', $url);
    }

    /**
     * {@inheritDoc}
     */
    public function cleanup()
    {
        return;
    }
}
