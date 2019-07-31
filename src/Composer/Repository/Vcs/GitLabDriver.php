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
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Downloader\TransportException;
use Composer\Util\RemoteFilesystem;
use Composer\Util\GitLab;

/**
 * Driver for GitLab API, use the Git driver for local checkouts.
 *
 * @author Henrik Bjørnskov <henrik@bjrnskov.dk>
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabDriver extends VcsDriver
{
    private $scheme;
    private $namespace;
    private $repository;

    /**
     * @var array Project data returned by GitLab API
     */
    private $project;

    /**
     * @var array Keeps commits returned by GitLab API
     */
    private $commits = array();

    /**
     * @var array List of tag => reference
     */
    private $tags;

    /**
     * @var array List of branch => reference
     */
    private $branches;

    /**
     * Git Driver
     *
     * @var GitDriver
     */
    protected $gitDriver;

    /**
     * Defaults to true unless we can make sure it is public
     *
     * @var bool defines whether the repo is private or not
     */
    private $isPrivate = true;

    /**
     * @var bool true if the origin has a port number or a path component in it
     */
    private $hasNonstandardOrigin = false;

    const URL_REGEX = '#^(?:(?P<scheme>https?)://(?P<domain>.+?)(?::(?P<port>[0-9]+))?/|git@(?P<domain2>[^:]+):)(?P<parts>.+)/(?P<repo>[^/]+?)(?:\.git|/)?$#';

    /**
     * Extracts information from the repository url.
     *
     * SSH urls use https by default. Set "secure-http": false on the repository config to use http instead.
     *
     * {@inheritDoc}
     */
    public function initialize()
    {
        if (!preg_match(self::URL_REGEX, $this->url, $match)) {
            throw new \InvalidArgumentException('The URL provided is invalid. It must be the HTTP URL of a GitLab project.');
        }

        $guessedDomain = !empty($match['domain']) ? $match['domain'] : $match['domain2'];
        $configuredDomains = $this->config->get('gitlab-domains');
        $urlParts = explode('/', $match['parts']);

        $this->scheme = !empty($match['scheme'])
            ? $match['scheme']
            : (isset($this->repoConfig['secure-http']) && $this->repoConfig['secure-http'] === false ? 'http' : 'https')
        ;
        $this->originUrl = $this->determineOrigin($configuredDomains, $guessedDomain, $urlParts, $match['port']);

        if (false !== strpos($this->originUrl, ':') || false !== strpos($this->originUrl, '/')) {
            $this->hasNonstandardOrigin = true;
        }

        $this->namespace = implode('/', $urlParts);
        $this->repository = preg_replace('#(\.git)$#', '', $match['repo']);

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->namespace.'/'.$this->repository);

        $this->fetchProject();
    }

    /**
     * Updates the RemoteFilesystem instance.
     * Mainly useful for tests.
     *
     * @internal
     */
    public function setRemoteFilesystem(RemoteFilesystem $remoteFilesystem)
    {
        $this->remoteFilesystem = $remoteFilesystem;
    }

    /**
     * {@inheritdoc}
     */
    public function getFileContent($file, $identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getFileContent($file, $identifier);
        }

        // Convert the root identifier to a cacheable commit id
        if (!preg_match('{[a-f0-9]{40}}i', $identifier)) {
            $branches = $this->getBranches();
            if (isset($branches[$identifier])) {
                $identifier = $branches[$identifier];
            }
        }

        $resource = $this->getApiUrl().'/repository/files/'.$this->urlEncodeAll($file).'/raw?ref='.$identifier;

        try {
            $content = $this->getContents($resource);
        } catch (TransportException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return null;
        }

        return $content;
    }

    /**
     * {@inheritdoc}
     */
    public function getChangeDate($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getChangeDate($identifier);
        }

        if (isset($this->commits[$identifier])) {
            return new \DateTime($this->commits[$identifier]['committed_date']);
        }

        return new \DateTime();
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryUrl()
    {
        return $this->isPrivate ? $this->project['ssh_url_to_repo'] : $this->project['http_url_to_repo'];
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getUrl();
        }

        return $this->project['web_url'];
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        $url = $this->getApiUrl().'/repository/archive.zip?sha='.$identifier;

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getSource($identifier);
        }

        return array('type' => 'git', 'url' => $this->getRepositoryUrl(), 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getRootIdentifier();
        }

        return $this->project['default_branch'];
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getBranches();
        }

        if (!$this->branches) {
            $this->branches = $this->getReferences('branches');
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getTags();
        }

        if (!$this->tags) {
            $this->tags = $this->getReferences('tags');
        }

        return $this->tags;
    }

    /**
     * @return string Base URL for GitLab API v3
     */
    public function getApiUrl()
    {
        return $this->scheme.'://'.$this->originUrl.'/api/v4/projects/'.$this->urlEncodeAll($this->namespace).'%2F'.$this->urlEncodeAll($this->repository);
    }

    /**
     * Urlencode all non alphanumeric characters. rawurlencode() can not be used as it does not encode `.`
     *
     * @param  string $string
     * @return string
     */
    private function urlEncodeAll($string)
    {
        $encoded = '';
        for ($i = 0; isset($string[$i]); $i++) {
            $character = $string[$i];
            if (!ctype_alnum($character) && !in_array($character, array('-', '_'), true)) {
                $character = '%' . sprintf('%02X', ord($character));
            }
            $encoded .= $character;
        }

        return $encoded;
    }

    /**
     * @param string $type
     *
     * @return string[] where keys are named references like tags or branches and the value a sha
     */
    protected function getReferences($type)
    {
        $perPage = 100;
        $resource = $this->getApiUrl().'/repository/'.$type.'?per_page='.$perPage;

        $references = array();
        do {
            $data = JsonFile::parseJson($this->getContents($resource), $resource);

            foreach ($data as $datum) {
                $references[$datum['name']] = $datum['commit']['id'];

                // Keep the last commit date of a reference to avoid
                // unnecessary API call when retrieving the composer file.
                $this->commits[$datum['commit']['id']] = $datum['commit'];
            }

            if (count($data) >= $perPage) {
                $resource = $this->getNextPage();
            } else {
                $resource = false;
            }
        } while ($resource);

        return $references;
    }

    protected function fetchProject()
    {
        // we need to fetch the default branch from the api
        $resource = $this->getApiUrl();
        $this->project = JsonFile::parseJson($this->getContents($resource, true), $resource);
        if (isset($this->project['visibility'])) {
            $this->isPrivate = $this->project['visibility'] !== 'public';
        } else {
            // client is not authendicated, therefore repository has to be public
            $this->isPrivate = false;
        }
    }

    protected function attemptCloneFallback()
    {
        try {
            if ($this->isPrivate === false) {
                $url = $this->generatePublicUrl();
            } else {
                $url = $this->generateSshUrl();
            }

            // If this repository may be private and we
            // cannot ask for authentication credentials (because we
            // are not interactive) then we fallback to GitDriver.
            $this->setupGitDriver($url);

            return;
        } catch (\RuntimeException $e) {
            $this->gitDriver = null;

            $this->io->writeError('<error>Failed to clone the '.$url.' repository, try running in interactive mode so that you can enter your credentials</error>');
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
        if ($this->hasNonstandardOrigin) {
            return 'ssh://git@'.$this->originUrl.'/'.$this->namespace.'/'.$this->repository.'.git';
        }

        return 'git@' . $this->originUrl . ':'.$this->namespace.'/'.$this->repository.'.git';
    }

    protected function generatePublicUrl()
    {
        return $this->scheme . '://' . $this->originUrl . '/'.$this->namespace.'/'.$this->repository.'.git';
    }

    protected function setupGitDriver($url)
    {
        $this->gitDriver = new GitDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->process,
            $this->remoteFilesystem
        );
        $this->gitDriver->initialize();
    }

    /**
     * {@inheritDoc}
     */
    protected function getContents($url, $fetchingRepoData = false)
    {
        try {
            $res = parent::getContents($url);

            if ($fetchingRepoData) {
                $json = JsonFile::parseJson($res, $url);

                // force auth as the unauthenticated version of the API is broken
                if (!isset($json['default_branch'])) {
                    if (!empty($json['id'])) {
                        $this->isPrivate = false;
                    }

                    throw new TransportException('GitLab API seems to not be authenticated as it did not return a default_branch', 401);
                }
            }

            return $res;
        } catch (TransportException $e) {
            $gitLabUtil = new GitLab($this->io, $this->config, $this->process, $this->remoteFilesystem);

            switch ($e->getCode()) {
                case 401:
                case 404:
                    // try to authorize only if we are fetching the main /repos/foo/bar data, otherwise it must be a real 404
                    if (!$fetchingRepoData) {
                        throw $e;
                    }

                    if ($gitLabUtil->authorizeOAuth($this->originUrl)) {
                        return parent::getContents($url);
                    }

                    if (!$this->io->isInteractive()) {
                        return $this->attemptCloneFallback();
                    }
                    $this->io->writeError('<warning>Failed to download ' . $this->namespace . '/' . $this->repository . ':' . $e->getMessage() . '</warning>');
                    $gitLabUtil->authorizeOAuthInteractively($this->scheme, $this->originUrl, 'Your credentials are required to fetch private repository metadata (<info>'.$this->url.'</info>)');

                    return parent::getContents($url);

                case 403:
                    if (!$this->io->hasAuthentication($this->originUrl) && $gitLabUtil->authorizeOAuth($this->originUrl)) {
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
     * Uses the config `gitlab-domains` to see if the driver supports the url for the
     * repository given.
     *
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match(self::URL_REGEX, $url, $match)) {
            return false;
        }

        $scheme = !empty($match['scheme']) ? $match['scheme'] : null;
        $guessedDomain = !empty($match['domain']) ? $match['domain'] : $match['domain2'];
        $urlParts = explode('/', $match['parts']);

        if (false === self::determineOrigin((array) $config->get('gitlab-domains'), $guessedDomain, $urlParts, $match['port'])) {
            return false;
        }

        if ('https' === $scheme && !extension_loaded('openssl')) {
            $io->writeError('Skipping GitLab driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    private function getNextPage()
    {
        $headers = $this->remoteFilesystem->getLastHeaders();
        foreach ($headers as $header) {
            if (preg_match('{^link:\s*(.+?)\s*$}i', $header, $match)) {
                $links = explode(',', $match[1]);
                foreach ($links as $link) {
                    if (preg_match('{<(.+?)>; *rel="next"}', $link, $match)) {
                        return $match[1];
                    }
                }
            }
        }
    }

    /**
     * @param  array       $configuredDomains
     * @param  string      $guessedDomain
     * @param  array       $urlParts
     * @return bool|string
     */
    private static function determineOrigin(array $configuredDomains, $guessedDomain, array &$urlParts, $portNumber)
    {
        if (in_array($guessedDomain, $configuredDomains) || ($portNumber && in_array($guessedDomain.':'.$portNumber, $configuredDomains))) {
            if ($portNumber) {
                return $guessedDomain.':'.$portNumber;
            }
            return $guessedDomain;
        }

        if ($portNumber) {
            $guessedDomain .= ':'.$portNumber;
        }

        while (null !== ($part = array_shift($urlParts))) {
            $guessedDomain .= '/' . $part;

            if (in_array($guessedDomain, $configuredDomains) || ($portNumber && in_array(preg_replace('{:\d+}', '', $guessedDomain), $configuredDomains))) {
                return $guessedDomain;
            }
        }

        return false;
    }
}
