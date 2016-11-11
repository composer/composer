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
     * @var GitDriver
     */
    protected $sshDriver;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^https?://bitbucket\.org/([^/]+)/(.+?)(\.git|/?)$#', $this->url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];
        $this->originUrl = 'bitbucket.org';
        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->owner.'/'.$this->repository);
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if ($this->sshDriver) {
            return $this->sshDriver->getComposerInformation($identifier);
        }

        if (!isset($this->infoCache[$identifier])) {

            $composer = parent::getComposerInformation($identifier);

            // specials for bitbucket
            if (!isset($composer['support']['source'])) {
                $label = array_search($identifier, $this->getTags()) ?: array_search($identifier, $this->getBranches()) ?: $identifier;

                if (array_key_exists($label, $tags = $this->getTags())) {
                    $hash = $tags[$label];
                } elseif (array_key_exists($label, $branches = $this->getBranches())) {
                    $hash = $branches[$label];
                }

                if (! isset($hash)) {
                    $composer['support']['source'] = sprintf('https://%s/%s/%s/src', $this->originUrl, $this->owner, $this->repository);
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
                $composer['support']['issues'] = sprintf('https://%s/%s/%s/issues', $this->originUrl, $this->owner, $this->repository);
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritdoc}
     */
    public function getFileContent($file, $identifier) {
        if ($this->sshDriver) {
            return $this->sshDriver->getFileContent($file, $identifier);
        }

        if (preg_match('{[a-f0-9]{40}}i', $identifier) && $res = $this->cache->read($identifier . ':' . $file)) {
            return $res;
        }

        $resource = $this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository.'/src/'.$identifier.'/' . $file;
        $fileData = JsonFile::parseJson($this->getContents($resource), $resource);
        if (!is_array($fileData) || ! array_key_exists('data', $fileData)) {
            return null;
        }

        if (preg_match('{[a-f0-9]{40}}i', $identifier)) {
            $this->cache->write($identifier . ':' . $file, $fileData['data']);
        }

        return $fileData['data'];
    }

    /**
     * {@inheritdoc}
     */
    public function getChangeDate($identifier) {
        if ($this->sshDriver) {
            return $this->sshDriver->getChangeDate($identifier);
        }

        $resource = $this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository.'/changesets/'.$identifier;
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

            switch ($e->getCode()) {
                case 403:
                    if (!$this->io->hasAuthentication($this->originUrl) && $bitbucketUtil->authorizeOAuth($this->originUrl)) {
                        return parent::getContents($url);
                    }

                    if (!$this->io->isInteractive() && $fetchingRepoData) {
                        return $this->attemptCloneFallback();
                    }

                    throw $e;

                default:
                    throw $e;
            }
        }
    }

    /**
     * Generate an SSH URL
     *
     * @return string
     */
    protected function generateSshUrl()
    {
        return 'git@' . $this->originUrl . ':' . $this->owner.'/'.$this->repository.'.git';
    }

    protected function attemptCloneFallback()
    {
        try {
            $this->setupSshDriver($this->generateSshUrl());

            return;
        } catch (\RuntimeException $e) {
            $this->sshDriver = null;

            $this->io->writeError('<error>Failed to clone the '.$this->generateSshUrl().' repository, try running in interactive mode so that you can enter your Bitbucket OAuth consumer credentials</error>');
            throw $e;
        }
    }

    abstract protected function setupSshDriver($url);
}
