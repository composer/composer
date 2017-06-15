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

use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Composer\Util\Filesystem;

/**
 * A driver implementation for driver with authentication interaction.
 *
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class VcsDriver implements VcsDriverInterface
{
    /** @var string */
    protected $url;
    /** @var string */
    protected $originUrl;
    /** @var array */
    protected $repoConfig;
    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var ProcessExecutor */
    protected $process;
    /** @var RemoteFilesystem */
    protected $remoteFilesystem;
    /** @var array */
    protected $infoCache = array();
    /** @var Cache */
    protected $cache;

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
        if (Filesystem::isLocalPath($repoConfig['url'])) {
            $repoConfig['url'] = Filesystem::getPlatformPath($repoConfig['url']);
        }

        $this->url = $repoConfig['url'];
        $this->originUrl = $repoConfig['url'];
        $this->repoConfig = $repoConfig;
        $this->io = $io;
        $this->config = $config;
        $this->process = $process ?: new ProcessExecutor($io);
        $this->remoteFilesystem = $remoteFilesystem ?: Factory::createRemoteFilesystem($this->io, $config);
    }

    /**
     * Returns whether or not the given $identifier should be cached or not.
     *
     * @param  string $identifier
     * @return bool
     */
    protected function shouldCache($identifier)
    {
        return $this->cache && preg_match('{[a-f0-9]{40}}i', $identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            if ($this->shouldCache($identifier) && $res = $this->cache->read($identifier)) {
                return $this->infoCache[$identifier] = JsonFile::parseJson($res);
            }

            $composer = $this->getBaseComposerInformation($identifier);

            if ($this->shouldCache($identifier)) {
                $this->cache->write($identifier, json_encode($composer));
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    protected function getBaseComposerInformation($identifier)
    {
        $composerFileContent = $this->getFileContent('composer.json', $identifier);

        if (!$composerFileContent) {
            return null;
        }

        $composer = JsonFile::parseJson($composerFileContent, $identifier . ':composer.json');

        if (empty($composer['time']) && $changeDate = $this->getChangeDate($identifier)) {
            $composer['time'] = $changeDate->format(DATE_RFC3339);
        }

        return $composer;
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
        $options = isset($this->repoConfig['options']) ? $this->repoConfig['options'] : array();

        return $this->remoteFilesystem->getContents($this->originUrl, $url, false, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function cleanup()
    {
        return;
    }
}
