<?php

namespace Composer\Repository\Vcs;

use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Util\Bitbucket;

abstract class BitbucketDriver extends VcsDriver
{
    /** @var Cache */
    protected $cache;
    protected $owner;
    protected $repository;
    protected $hasIssues;
    protected $rootIdentifier;
    protected $tags;
    protected $branches;
    protected $infoCache = array();

    /**
     * @var VcsDriver
     */
    protected $fallbackDriver;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^https?://bitbucket\.org/([^/]+)/([^/]+?)(\.git|/?)$#', $this->url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];
        $this->originUrl = 'bitbucket.org';
        $this->cache = new Cache(
            $this->io,
            implode('/', array(
                $this->config->get('cache-repo-dir'),
                $this->originUrl,
                $this->owner,
                $this->repository
            ))
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getComposerInformation($identifier);
        }

        if (!isset($this->infoCache[$identifier])) {
            if ($this->shouldCache($identifier) && $res = $this->cache->read($identifier)) {
                return $this->infoCache[$identifier] = JsonFile::parseJson($res);
            }

            $composer = $this->getBaseComposerInformation($identifier);

            // specials for bitbucket
            if (!isset($composer['support']['source'])) {
                $label = array_search(
                    $identifier,
                    $this->getTags()
                ) ?: array_search(
                    $identifier,
                    $this->getBranches()
                ) ?: $identifier;

                if (array_key_exists($label, $tags = $this->getTags())) {
                    $hash = $tags[$label];
                } elseif (array_key_exists($label, $branches = $this->getBranches())) {
                    $hash = $branches[$label];
                }

                if (! isset($hash)) {
                    $composer['support']['source'] = sprintf(
                        'https://%s/%s/%s/src',
                        $this->originUrl,
                        $this->owner,
                        $this->repository
                    );
                } else {
                    $composer['support']['source'] = sprintf(
                        'https://%s/%s/%s/src/%s/?at=%s',
                        $this->originUrl,
                        $this->owner,
                        $this->repository,
                        $hash,
                        $label
                    );
                }
            }
            if (!isset($composer['support']['issues']) && $this->hasIssues) {
                $composer['support']['issues'] = sprintf(
                    'https://%s/%s/%s/issues',
                    $this->originUrl,
                    $this->owner,
                    $this->repository
                );
            }

            $this->infoCache[$identifier] = $composer;

            if ($this->shouldCache($identifier)) {
                $this->cache->write($identifier, json_encode($composer));
            }
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritdoc}
     */
    public function getFileContent($file, $identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getFileContent($file, $identifier);
        }

        $resource = $this->getScheme() . '://api.bitbucket.org/1.0/repositories/'
                    . $this->owner . '/' . $this->repository . '/src/' . $identifier . '/' . $file;
        $fileData = JsonFile::parseJson($this->getContents($resource), $resource);
        if (!is_array($fileData) || ! array_key_exists('data', $fileData)) {
            return null;
        }

        return $fileData['data'];
    }

    /**
     * {@inheritdoc}
     */
    public function getChangeDate($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getChangeDate($identifier);
        }

        $resource = $this->getScheme() . '://api.bitbucket.org/1.0/repositories/'
                    . $this->owner . '/' . $this->repository . '/changesets/' . $identifier;
        $changeset = JsonFile::parseJson($this->getContents($resource), $resource);

        return new \DateTime($changeset['timestamp']);
    }

    /**
     * Get the remote content.
     *
     * @param string $url The URL of content
     * @param bool $fetchingRepoData
     *
     * @return mixed The result
     */
    protected function getContentsWithOAuthCredentials($url, $fetchingRepoData = false)
    {
        try {
            return parent::getContents($url);
        } catch (TransportException $e) {
            $bitbucketUtil = new Bitbucket($this->io, $this->config, $this->process, $this->remoteFilesystem);

            if (403 === $e->getCode() || (401 === $e->getCode() && strpos($e->getMessage(), 'Could not authenticate against') === 0)) {
                if (!$this->io->hasAuthentication($this->originUrl)
                    && $bitbucketUtil->authorizeOAuth($this->originUrl)
                ) {
                    return parent::getContents($url);
                }

                if (!$this->io->isInteractive() && $fetchingRepoData) {
                    return $this->attemptCloneFallback();
                }
            }

            throw $e;
        }
    }

    /**
     * Generate an SSH URL
     *
     * @return string
     */
    abstract protected function generateSshUrl();

    protected function attemptCloneFallback()
    {
        try {
            $this->setupFallbackDriver($this->generateSshUrl());
        } catch (\RuntimeException $e) {
            $this->fallbackDriver = null;

            $this->io->writeError(
                '<error>Failed to clone the ' . $this->generateSshUrl() . ' repository, try running in interactive mode'
                    . ' so that you can enter your Bitbucket OAuth consumer credentials</error>'
            );
            throw $e;
        }
    }

    abstract protected function setupFallbackDriver($url);
}
