<?php

namespace Composer\Repository\Vcs;

use Composer\Cache;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\BitbucketServer;

class BitbucketServerDriver extends BitbucketDriver
{
    /** @var Cache */
    protected $cache;
    protected $ownerOrProject;
    protected $repository;
    protected $hasIssues;
    protected $rootIdentifier;
    protected $tags;
    protected $branches;
    protected $infoCache = array();
    protected $branchesUrl = '';
    protected $tagsUrl = '';
    protected $homeUrl = '';
    protected $website = '';
    protected $cloneHttpsUrl = '';

    /**
     * @var VcsDriver
     */
    protected $fallbackDriver;
    /** @var string|null if set either git or hg */
    protected $vcsType;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        if (strpos($this->url, 'ssh:') === 0) {
            preg_match('#^ssh://(?:[^@]+)@([^/]+)/([^/]+)/([^/]+?)(\.git|/?)$#i', $this->url, $match);
        } else {
            preg_match('#^https?://([^/]+)/scm/([^/]+)/([^/]+?)(\.git|/?)$#i', $this->url, $match);
        }
        $this->ownerOrProject = $match[2];
        $this->repository = $match[3];
        $this->originUrl = $match[1];
        $this->cache = new Cache(
            $this->io,
            implode('/', array(
                $this->config->get('cache-repo-dir'),
                $this->originUrl,
                $this->ownerOrProject,
                $this->repository,
            ))
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getUrl();
        }

        return $this->cloneHttpsUrl;
    }

    /**
     * Attempts to fetch the repository data via the BitBucket API and
     * sets some parameters which are used in other methods
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp158
     *
     * @return bool
     */
    protected function getRepoData()
    {
        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s',
            $this->originUrl,
            $this->ownerOrProject,
            $this->repository
        );

        $repoData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource, true), $resource);
        if ($this->fallbackDriver) {
            return false;
        }
        $this->parseCloneUrls($repoData['links']['clone']);

        $this->hasIssues = false;
        $this->vcsType = $repoData['scmId'];

        return true;
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
                $composer = JsonFile::parseJson($res);
            } else {
                $composer = $this->getBaseComposerInformation($identifier);

                if ($this->shouldCache($identifier)) {
                    $this->cache->write($identifier, json_encode($composer));
                }
            }

            if ($composer) {
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
                            'https://%s/projects/%s/repos/%s/browse',
                            $this->originUrl,
                            $this->ownerOrProject,
                            $this->repository
                        );
                    } else {
                        $composer['support']['source'] = sprintf(
                            'https://%s/projects/%s/repos/%s/browse?at=%s',
                            $this->originUrl,
                            $this->ownerOrProject,
                            $this->repository,
                            $identifier
                        );
                    }
                }
                if (!isset($composer['support']['issues']) && $this->hasIssues) {
                    $composer['support']['issues'] = sprintf(
                        'https://%s/%s/%s/issues',
                        $this->originUrl,
                        $this->ownerOrProject,
                        $this->repository
                    );
                }
                if (!isset($composer['homepage'])) {
                    $composer['homepage'] = empty($this->website) ? $this->homeUrl : $this->website;
                }
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritdoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp302
     */
    public function getFileContent($file, $identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getFileContent($file, $identifier);
        }

        if (strpos($identifier, '/') !== false) {
            $branches = $this->getBranches();
            if (isset($branches[$identifier])) {
                $identifier = $branches[$identifier];
            }
        }

        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/raw/%s?at=%s',
            $this->originUrl,
            $this->ownerOrProject,
            $this->repository,
            $file,
            $identifier
        );

        return $this->getContentsWithOAuthCredentials($resource);
    }

    /**
     * {@inheritdoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp193
     */
    public function getChangeDate($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getChangeDate($identifier);
        }

        if (strpos($identifier, '/') !== false) {
            $branches = $this->getBranches();
            if (isset($branches[$identifier])) {
                $identifier = $branches[$identifier];
            }
        }

        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/commits/%s',
            $this->originUrl,
            $this->ownerOrProject,
            $this->repository,
            $identifier
        );

        $commit = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);

        $dateTime = new \DateTime();
        return $dateTime->setTimestamp($commit['authorTimestamp'] / 1000);
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getSource($identifier);
        }

        return array('type' => $this->vcsType, 'url' => $this->getUrl(), 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp178
     */
    public function getDist($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getDist($identifier);
        }

        $url = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/archive?at=%s&format=zip',
            $this->originUrl,
            $this->ownerOrProject,
            $this->repository,
            $identifier
        );

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp321
     */
    public function getTags()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getTags();
        }

        if (null === $this->tags) {
            $this->tags = array();
            $resource = sprintf(
                'https://%s/rest/api/1.0/projects/%s/repos/%s/tags?%s',
                $this->originUrl,
                $this->ownerOrProject,
                $this->repository,
                http_build_query(
                    array(
                        'start' => 0,
                        'limit' => 100,
                        'orderBy' => 'MODIFICATION',
                    ),
                    null,
                    '&'
                )
            );
            $hasNext = true;
            while ($hasNext) {
                $tagsData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
                foreach ($tagsData['values'] as $data) {
                    $this->tags[$data['displayId']] = $data['latestCommit'];
                }

                if (isset($tagsData['isLastPage']) && $tagsData['isLastPage'] === true) {
                    $hasNext = false;
                } else {
                    $resource = sprintf(
                        'https://%s/rest/api/1.0/projects/%s/repos/%s/tags?%s',
                        $this->originUrl,
                        $this->ownerOrProject,
                        $this->repository,
                        http_build_query(
                            array(
                                'start' => $tagsData['nextPageStart'],
                                'limit' => 100,
                                'orderBy' => 'MODIFICATION',
                            ),
                            null,
                            '&'
                        )
                    );
                }
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp180
     */
    public function getBranches()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getBranches();
        }

        if (null === $this->branches) {
            $this->branches = array();
            $resource = sprintf(
                'https://%s/rest/api/1.0/projects/%s/repos/%s/branches?%s',
                $this->originUrl,
                $this->ownerOrProject,
                $this->repository,
                http_build_query(
                    array(
                        'start' => 0,
                        'limit' => 100,
                        'orderBy' => 'MODIFICATION',
                    ),
                    null,
                    '&'
                )
            );
            $hasNext = true;
            while ($hasNext) {
                $branchData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
                foreach ($branchData['values'] as $data) {
                    $this->branches[$data['displayId']] = $data['latestCommit'];
                }
                if (isset($branchData['isLastPage']) && $branchData['isLastPage'] === true) {
                    $hasNext = false;
                } else {
                    $resource = sprintf(
                        'https://%s/rest/api/1.0/projects/%s/repos/%s/branches?%s',
                        $this->originUrl,
                        $this->ownerOrProject,
                        $this->repository,
                        http_build_query(
                            array(
                                'start' => $branchData['nextPageStart'],
                                'limit' => 100,
                                'orderBy' => 'MODIFICATION',
                            ),
                            null,
                            '&'
                        )
                    );
                }
            }
        }

        return $this->branches;
    }

    /**
     * Get the remote content.
     *
     * @param string $url              The URL of content
     * @param bool   $fetchingRepoData
     *
     * @return mixed The result
     */
    protected function getContentsWithOAuthCredentials($url, $fetchingRepoData = false)
    {
        try {
            return $this->getContents($url);
        } catch (TransportException $e) {
            $bitbucketUtil = new BitbucketServer($this->io, $this->config, $this->process, $this->remoteFilesystem);

            if (403 === $e->getCode() || (401 === $e->getCode() && strpos($e->getMessage(), 'URL required authentication.') !== false)) {
                if (!$this->io->hasAuthentication($this->originUrl)
                    && $bitbucketUtil->authorizeOAuth($this->originUrl)
                ) {
                    return $this->getContents($url);
                }

                if (!$this->io->isInteractive() && $fetchingRepoData) {
                    return $this->attemptCloneFallback();
                }
            }

            throw $e;
        }
    }

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

    /**
     * @param  array $cloneLinks
     * @return void
     */
    protected function parseCloneUrls(array $cloneLinks)
    {
        foreach ($cloneLinks as $cloneLink) {
            if ($cloneLink['name'] === 'http') {
                $this->cloneHttpsUrl = preg_replace('/https:\/\/([^@]+@)?/', 'https://', $cloneLink['href']);
            }
        }
    }

    /**
     * @return array|null
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp183
     */
    protected function getMainBranchData()
    {
        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/branches/default',
            $this->originUrl,
            $this->ownerOrProject,
            $this->repository
        );

        $data = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
        if (isset($data['displayId'])) {
            return array('name' => $data['displayId']);
        }

        return null;
    }

    public function getRootIdentifier()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getRootIdentifier();
        }

        if (null === $this->rootIdentifier) {
            if (! $this->getRepoData()) {
                return $this->fallbackDriver->getRootIdentifier();
            }

            if ($this->vcsType !== 'git') {
                throw new \RuntimeException(
                    $this->url.' does not appear to be a git repository, use '.
                    $this->cloneHttpsUrl.' if this is a mercurial bitbucket repository'
                );
            }

            $mainBranchData = $this->getMainBranchData();
            $this->rootIdentifier = !empty($mainBranchData['name']) ? $mainBranchData['name'] : 'master';
        }

        return $this->rootIdentifier;
    }

    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match('#^https?://bitbucket\.org/([^/]+)/(.+?)\.git$#i', $url)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            $io->writeError('Skipping Bitbucket git driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function generateSshUrl()
    {
        return 'git@' . $this->originUrl . '/' . $this->ownerOrProject.'/'.$this->repository.'.git';
    }

    /**
     * {@inheritdoc}
     */
    protected function setupFallbackDriver($url)
    {
        $this->fallbackDriver = new GitDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->process,
            $this->remoteFilesystem
        );
        $this->fallbackDriver->initialize();
    }
}
