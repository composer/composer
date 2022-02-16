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

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Util\Bitbucket;
use Composer\Util\Http\Response;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class GitBitbucketDriver extends VcsDriver
{
    /** @var string */
    protected $owner;
    /** @var string */
    protected $repository;
    /** @var bool */
    private $hasIssues = false;
    /** @var ?string */
    private $rootIdentifier;
    /** @var array<string, string> Map of tag name to identifier */
    private $tags;
    /** @var array<string, string> Map of branch name to identifier */
    private $branches;
    /** @var string */
    private $branchesUrl = '';
    /** @var string */
    private $tagsUrl = '';
    /** @var string */
    private $homeUrl = '';
    /** @var string */
    private $website = '';
    /** @var string */
    private $cloneHttpsUrl = '';

    /**
     * @var ?VcsDriver
     */
    protected $fallbackDriver = null;
    /** @var string|null if set either git or hg */
    private $vcsType;

    /**
     * @inheritDoc
     */
    public function initialize()
    {
        if (!Preg::isMatch('#^https?://bitbucket\.org/([^/]+)/([^/]+?)(\.git|/?)?$#i', $this->url, $match)) {
            throw new \InvalidArgumentException(sprintf('The Bitbucket repository URL %s is invalid. It must be the HTTPS URL of a Bitbucket repository.', $this->url));
        }

        $this->owner = $match[1];
        $this->repository = $match[2];
        $this->originUrl = 'bitbucket.org';
        $this->cache = new Cache(
            $this->io,
            implode('/', array(
                $this->config->get('cache-repo-dir'),
                $this->originUrl,
                $this->owner,
                $this->repository,
            ))
        );
        $this->cache->setReadOnly($this->config->get('cache-read-only'));
    }

    /**
     * @inheritDoc
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
     * @return bool
     * @phpstan-impure
     */
    protected function getRepoData()
    {
        $resource = sprintf(
            'https://api.bitbucket.org/2.0/repositories/%s/%s?%s',
            $this->owner,
            $this->repository,
            http_build_query(
                array('fields' => '-project,-owner'),
                '',
                '&'
            )
        );

        $repoData = $this->fetchWithOAuthCredentials($resource, true)->decodeJson();
        if ($this->fallbackDriver) {
            return false;
        }
        $this->parseCloneUrls($repoData['links']['clone']);

        $this->hasIssues = !empty($repoData['has_issues']);
        $this->branchesUrl = $repoData['links']['branches']['href'];
        $this->tagsUrl = $repoData['links']['tags']['href'];
        $this->homeUrl = $repoData['links']['html']['href'];
        $this->website = $repoData['website'];
        $this->vcsType = $repoData['scm'];

        return true;
    }

    /**
     * @inheritDoc
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

                    if (!isset($hash)) {
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
                if (!isset($composer['homepage'])) {
                    $composer['homepage'] = empty($this->website) ? $this->homeUrl : $this->website;
                }
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * @inheritDoc
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
            'https://api.bitbucket.org/2.0/repositories/%s/%s/src/%s/%s',
            $this->owner,
            $this->repository,
            $identifier,
            $file
        );

        return $this->fetchWithOAuthCredentials($resource)->getBody();
    }

    /**
     * @inheritDoc
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
            'https://api.bitbucket.org/2.0/repositories/%s/%s/commit/%s?fields=date',
            $this->owner,
            $this->repository,
            $identifier
        );
        $commit = $this->fetchWithOAuthCredentials($resource)->decodeJson();

        return new \DateTime($commit['date']);
    }

    /**
     * @inheritDoc
     */
    public function getSource($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getSource($identifier);
        }

        return array('type' => $this->vcsType, 'url' => $this->getUrl(), 'reference' => $identifier);
    }

    /**
     * @inheritDoc
     */
    public function getDist($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getDist($identifier);
        }

        $url = sprintf(
            'https://bitbucket.org/%s/%s/get/%s.zip',
            $this->owner,
            $this->repository,
            $identifier
        );

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * @inheritDoc
     */
    public function getTags()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getTags();
        }

        if (null === $this->tags) {
            $tags = array();
            $resource = sprintf(
                '%s?%s',
                $this->tagsUrl,
                http_build_query(
                    array(
                        'pagelen' => 100,
                        'fields' => 'values.name,values.target.hash,next',
                        'sort' => '-target.date',
                    ),
                    '',
                    '&'
                )
            );
            $hasNext = true;
            while ($hasNext) {
                $tagsData = $this->fetchWithOAuthCredentials($resource)->decodeJson();
                foreach ($tagsData['values'] as $data) {
                    $tags[$data['name']] = $data['target']['hash'];
                }
                if (empty($tagsData['next'])) {
                    $hasNext = false;
                } else {
                    $resource = $tagsData['next'];
                }
            }

            $this->tags = $tags;
        }

        return $this->tags;
    }

    /**
     * @inheritDoc
     */
    public function getBranches()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getBranches();
        }

        if (null === $this->branches) {
            $branches = array();
            $resource = sprintf(
                '%s?%s',
                $this->branchesUrl,
                http_build_query(
                    array(
                        'pagelen' => 100,
                        'fields' => 'values.name,values.target.hash,values.heads,next',
                        'sort' => '-target.date',
                    ),
                    '',
                    '&'
                )
            );
            $hasNext = true;
            while ($hasNext) {
                $branchData = $this->fetchWithOAuthCredentials($resource)->decodeJson();
                foreach ($branchData['values'] as $data) {
                    $branches[$data['name']] = $data['target']['hash'];
                }
                if (empty($branchData['next'])) {
                    $hasNext = false;
                } else {
                    $resource = $branchData['next'];
                }
            }

            $this->branches = $branches;
        }

        return $this->branches;
    }

    /**
     * Get the remote content.
     *
     * @param string $url              The URL of content
     * @param bool   $fetchingRepoData
     *
     * @return Response The result
     *
     * @phpstan-impure
     */
    protected function fetchWithOAuthCredentials($url, $fetchingRepoData = false)
    {
        try {
            return parent::getContents($url);
        } catch (TransportException $e) {
            $bitbucketUtil = new Bitbucket($this->io, $this->config, $this->process, $this->httpDownloader);

            if (403 === $e->getCode() || (401 === $e->getCode() && strpos($e->getMessage(), 'Could not authenticate against') === 0)) {
                if (!$this->io->hasAuthentication($this->originUrl)
                    && $bitbucketUtil->authorizeOAuth($this->originUrl)
                ) {
                    return parent::getContents($url);
                }

                if (!$this->io->isInteractive() && $fetchingRepoData) {
                    $this->attemptCloneFallback();

                    return new Response(array('url' => 'dummy'), 200, array(), 'null');
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
    protected function generateSshUrl()
    {
        return 'git@' . $this->originUrl . ':' . $this->owner.'/'.$this->repository.'.git';
    }

    /**
     * @phpstan-impure
     *
     * @return true
     * @throws \RuntimeException
     */
    protected function attemptCloneFallback()
    {
        try {
            $this->setupFallbackDriver($this->generateSshUrl());

            return true;
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
     * @param  string $url
     * @return void
     */
    protected function setupFallbackDriver($url)
    {
        $this->fallbackDriver = new GitDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->httpDownloader,
            $this->process
        );
        $this->fallbackDriver->initialize();
    }

    /**
     * @param  array<array{name: string, href: string}> $cloneLinks
     * @return void
     */
    protected function parseCloneUrls(array $cloneLinks)
    {
        foreach ($cloneLinks as $cloneLink) {
            if ($cloneLink['name'] === 'https') {
                // Format: https://(user@)bitbucket.org/{user}/{repo}
                // Strip username from URL (only present in clone URL's for private repositories)
                $this->cloneHttpsUrl = Preg::replace('/https:\/\/([^@]+@)?/', 'https://', $cloneLink['href']);
            }
        }
    }

    /**
     * @return (array{name: string}&mixed[])|null
     */
    protected function getMainBranchData()
    {
        $resource = sprintf(
            'https://api.bitbucket.org/2.0/repositories/%s/%s?fields=mainbranch',
            $this->owner,
            $this->repository
        );

        $data = $this->fetchWithOAuthCredentials($resource)->decodeJson();
        if (isset($data['mainbranch'])) {
            return $data['mainbranch'];
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getRootIdentifier();
        }

        if (null === $this->rootIdentifier) {
            if (!$this->getRepoData()) {
                if (!$this->fallbackDriver) {
                    throw new \LogicException('A fallback driver should be setup if getRepoData returns false');
                }

                return $this->fallbackDriver->getRootIdentifier();
            }

            if ($this->vcsType !== 'git') {
                throw new \RuntimeException(
                    $this->url.' does not appear to be a git repository, use '.
                    $this->cloneHttpsUrl.' but remember that Bitbucket no longer supports the mercurial repositories. '.
                    'https://bitbucket.org/blog/sunsetting-mercurial-support-in-bitbucket'
                );
            }

            $mainBranchData = $this->getMainBranchData();
            $this->rootIdentifier = !empty($mainBranchData['name']) ? $mainBranchData['name'] : 'master';
        }

        return $this->rootIdentifier;
    }

    /**
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!Preg::isMatch('#^https?://bitbucket\.org/([^/]+)/([^/]+?)(\.git|/?)?$#i', $url)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            $io->writeError('Skipping Bitbucket git driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }
}
