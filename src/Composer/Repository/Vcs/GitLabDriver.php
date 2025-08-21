<?php declare(strict_types=1);

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
use Composer\Pcre\Preg;
use Composer\Util\HttpDownloader;
use Composer\Util\GitLab;
use Composer\Util\Http\Response;

/**
 * Driver for GitLab API, use the Git driver for local checkouts.
 *
 * @author Henrik Bjørnskov <henrik@bjrnskov.dk>
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabDriver extends VcsDriver
{
    /**
     * @var string
     * @phpstan-var 'https'|'http'
     */
    private $scheme;
    /** @var string */
    private $namespace;
    /** @var string */
    private $repository;

    /**
     * @var mixed[] Project data returned by GitLab API
     */
    private $project = null;

    /**
     * @var array<string|int, mixed[]> Keeps commits returned by GitLab API as commit id => info
     */
    private $commits = [];

    /** @var array<int|string, string> Map of tag name to identifier */
    private $tags;

    /** @var array<int|string, string> Map of branch name to identifier */
    private $branches;

    /**
     * Git Driver
     *
     * @var ?GitDriver
     */
    protected $gitDriver = null;

    /**
     * Protocol to force use of for repository URLs.
     *
     * @var string One of ssh, http
     */
    protected $protocol;

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

    public const URL_REGEX = '#^(?:(?P<scheme>https?)://(?P<domain>.+?)(?::(?P<port>[0-9]+))?/|git@(?P<domain2>[^:]+):)(?P<parts>.+)/(?P<repo>[^/]+?)(?:\.git|/)?$#';

    /**
     * Extracts information from the repository url.
     *
     * SSH urls use https by default. Set "secure-http": false on the repository config to use http instead.
     *
     * @inheritDoc
     */
    public function initialize(): void
    {
        if (!Preg::isMatch(self::URL_REGEX, $this->url, $match)) {
            throw new \InvalidArgumentException(sprintf('The GitLab repository URL %s is invalid. It must be the HTTP URL of a GitLab project.', $this->url));
        }

        $guessedDomain = $match['domain'] ?? (string) $match['domain2'];
        $configuredDomains = $this->config->get('gitlab-domains');
        $urlParts = explode('/', $match['parts']);

        $this->scheme = in_array($match['scheme'], ['https', 'http'], true)
            ? $match['scheme']
            : (isset($this->repoConfig['secure-http']) && $this->repoConfig['secure-http'] === false ? 'http' : 'https')
        ;
        $origin = self::determineOrigin($configuredDomains, $guessedDomain, $urlParts, $match['port']);
        if (false === $origin) {
            throw new \LogicException('It should not be possible to create a gitlab driver with an unparsable origin URL ('.$this->url.')');
        }
        $this->originUrl = $origin;

        if (is_string($protocol = $this->config->get('gitlab-protocol'))) {
            // https treated as a synonym for http.
            if (!in_array($protocol, ['git', 'http', 'https'], true)) {
                throw new \RuntimeException('gitlab-protocol must be one of git, http.');
            }
            $this->protocol = $protocol === 'git' ? 'ssh' : 'http';
        }

        if (false !== strpos($this->originUrl, ':') || false !== strpos($this->originUrl, '/')) {
            $this->hasNonstandardOrigin = true;
        }

        $this->namespace = implode('/', $urlParts);
        $this->repository = Preg::replace('#(\.git)$#', '', $match['repo']);

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->namespace.'/'.$this->repository);
        $this->cache->setReadOnly($this->config->get('cache-read-only'));

        $this->fetchProject();
    }

    /**
     * Updates the HttpDownloader instance.
     * Mainly useful for tests.
     *
     * @internal
     */
    public function setHttpDownloader(HttpDownloader $httpDownloader): void
    {
        $this->httpDownloader = $httpDownloader;
    }

    /**
     * @inheritDoc
     */
    public function getComposerInformation(string $identifier): ?array
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getComposerInformation($identifier);
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

            if (null !== $composer) {
                // specials for gitlab (this data is only available if authentication is provided)
                if (isset($composer['support']) && !is_array($composer['support'])) {
                    $composer['support'] = [];
                }
                if (!isset($composer['support']['source']) && isset($this->project['web_url'])) {
                    $label = array_search($identifier, $this->getTags(), true) ?: array_search($identifier, $this->getBranches(), true) ?: $identifier;
                    $composer['support']['source'] = sprintf('%s/-/tree/%s', $this->project['web_url'], $label);
                }
                if (!isset($composer['support']['issues']) && !empty($this->project['issues_enabled']) && isset($this->project['web_url'])) {
                    $composer['support']['issues'] = sprintf('%s/-/issues', $this->project['web_url']);
                }
                if (!isset($composer['abandoned']) && !empty($this->project['archived'])) {
                    $composer['abandoned'] = true;
                }
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * @inheritDoc
     */
    public function getFileContent(string $file, string $identifier): ?string
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getFileContent($file, $identifier);
        }

        // Convert the root identifier to a cacheable commit id
        if (!Preg::isMatch('{[a-f0-9]{40}}i', $identifier)) {
            $branches = $this->getBranches();
            if (isset($branches[$identifier])) {
                $identifier = $branches[$identifier];
            }
        }

        $resource = $this->getApiUrl().'/repository/files/'.$this->urlEncodeAll($file).'/raw?ref='.$identifier;

        try {
            $content = $this->getContents($resource)->getBody();
        } catch (TransportException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return null;
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getChangeDate($identifier);
        }

        if (isset($this->commits[$identifier])) {
            return new \DateTimeImmutable($this->commits[$identifier]['committed_date']);
        }

        return null;
    }

    public function getRepositoryUrl(): string
    {
        if ($this->protocol) {
            return $this->project["{$this->protocol}_url_to_repo"];
        }

        return $this->isPrivate ? $this->project['ssh_url_to_repo'] : $this->project['http_url_to_repo'];
    }

    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getUrl();
        }

        return $this->project['web_url'];
    }

    /**
     * @inheritDoc
     */
    public function getDist(string $identifier): ?array
    {
        $url = $this->getApiUrl().'/repository/archive.zip?sha='.$identifier;

        return ['type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => ''];
    }

    /**
     * @inheritDoc
     */
    public function getSource(string $identifier): array
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getSource($identifier);
        }

        return ['type' => 'git', 'url' => $this->getRepositoryUrl(), 'reference' => $identifier];
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier(): string
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getRootIdentifier();
        }

        return $this->project['default_branch'];
    }

    /**
     * @inheritDoc
     */
    public function getBranches(): array
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getBranches();
        }

        if (null === $this->branches) {
            $this->branches = $this->getReferences('branches');
        }

        return $this->branches;
    }

    /**
     * @inheritDoc
     */
    public function getTags(): array
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getTags();
        }

        if (null === $this->tags) {
            $this->tags = $this->getReferences('tags');
        }

        return $this->tags;
    }

    /**
     * @return string Base URL for GitLab API v3
     */
    public function getApiUrl(): string
    {
        return $this->scheme.'://'.$this->originUrl.'/api/v4/projects/'.$this->urlEncodeAll($this->namespace).'%2F'.$this->urlEncodeAll($this->repository);
    }

    /**
     * Urlencode all non alphanumeric characters. rawurlencode() can not be used as it does not encode `.`
     */
    private function urlEncodeAll(string $string): string
    {
        $encoded = '';
        for ($i = 0; isset($string[$i]); $i++) {
            $character = $string[$i];
            if (!ctype_alnum($character) && !in_array($character, ['-', '_'], true)) {
                $character = '%' . sprintf('%02X', ord($character));
            }
            $encoded .= $character;
        }

        return $encoded;
    }

    /**
     * @return string[] where keys are named references like tags or branches and the value a sha
     */
    protected function getReferences(string $type): array
    {
        $perPage = 100;
        $resource = $this->getApiUrl().'/repository/'.$type.'?per_page='.$perPage;

        $references = [];
        do {
            $response = $this->getContents($resource);
            $data = $response->decodeJson();

            foreach ($data as $datum) {
                $references[$datum['name']] = $datum['commit']['id'];

                // Keep the last commit date of a reference to avoid
                // unnecessary API call when retrieving the composer file.
                $this->commits[$datum['commit']['id']] = $datum['commit'];
            }

            if (count($data) >= $perPage) {
                $resource = $this->getNextPage($response);
            } else {
                $resource = false;
            }
        } while ($resource);

        return $references;
    }

    protected function fetchProject(): void
    {
        if (!is_null($this->project)) {
            return;
        }

        // we need to fetch the default branch from the api
        $resource = $this->getApiUrl();
        $this->project = $this->getContents($resource, true)->decodeJson();
        if (isset($this->project['visibility'])) {
            $this->isPrivate = $this->project['visibility'] !== 'public';
        } else {
            // client is not authenticated, therefore repository has to be public
            $this->isPrivate = false;
        }
    }

    /**
     * @phpstan-impure
     *
     * @return true
     * @throws \RuntimeException
     */
    protected function attemptCloneFallback(): bool
    {
        if ($this->isPrivate === false) {
            $url = $this->generatePublicUrl();
        } else {
            $url = $this->generateSshUrl();
        }

        try {
            // If this repository may be private and we
            // cannot ask for authentication credentials (because we
            // are not interactive) then we fallback to GitDriver.
            $this->setupGitDriver($url);

            return true;
        } catch (\RuntimeException $e) {
            $this->gitDriver = null;

            $this->io->writeError('<error>Failed to clone the '.$url.' repository, try running in interactive mode so that you can enter your credentials</error>');
            throw $e;
        }
    }

    /**
     * Generate an SSH URL
     */
    protected function generateSshUrl(): string
    {
        if ($this->hasNonstandardOrigin) {
            return 'ssh://git@'.$this->originUrl.'/'.$this->namespace.'/'.$this->repository.'.git';
        }

        return 'git@' . $this->originUrl . ':'.$this->namespace.'/'.$this->repository.'.git';
    }

    protected function generatePublicUrl(): string
    {
        return $this->scheme . '://' . $this->originUrl . '/'.$this->namespace.'/'.$this->repository.'.git';
    }

    protected function setupGitDriver(string $url): void
    {
        $this->gitDriver = new GitDriver(
            ['url' => $url],
            $this->io,
            $this->config,
            $this->httpDownloader,
            $this->process
        );
        $this->gitDriver->initialize();
    }

    /**
     * @inheritDoc
     */
    protected function getContents(string $url, bool $fetchingRepoData = false): Response
    {
        try {
            $response = parent::getContents($url);

            if ($fetchingRepoData) {
                $json = $response->decodeJson();

                // Accessing the API with a token with Guest (10) or Planner (15) access will return
                // more data than unauthenticated access but no default_branch data
                // accessing files via the API will then also fail
                if (!isset($json['default_branch']) && isset($json['permissions'])) {
                    $this->isPrivate = $json['visibility'] !== 'public';

                    $moreThanGuestAccess = false;
                    // Check both access levels (e.g. project, group)
                    // - value will be null if no access is set
                    // - value will be array with key access_level if set
                    foreach ($json['permissions'] as $permission) {
                        if ($permission && $permission['access_level'] >= 20) {
                            $moreThanGuestAccess = true;
                        }
                    }

                    if (!$moreThanGuestAccess) {
                        $this->io->writeError('<warning>GitLab token with Guest or Planner only access detected</warning>');

                        $this->attemptCloneFallback();

                        return new Response(['url' => 'dummy'], 200, [], 'null');
                    }
                }

                // force auth as the unauthenticated version of the API is broken
                if (!isset($json['default_branch'])) {
                    // GitLab allows you to disable the repository inside a project to use a project only for issues and wiki
                    if (isset($json['repository_access_level']) && $json['repository_access_level'] === 'disabled') {
                        throw new TransportException('The GitLab repository is disabled in the project', 400);
                    }

                    if (!empty($json['id'])) {
                        $this->isPrivate = false;
                    }

                    throw new TransportException('GitLab API seems to not be authenticated as it did not return a default_branch', 401);
                }
            }

            return $response;
        } catch (TransportException $e) {
            $gitLabUtil = new GitLab($this->io, $this->config, $this->process, $this->httpDownloader);

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

                    if ($gitLabUtil->isOAuthExpired($this->originUrl) && $gitLabUtil->authorizeOAuthRefresh($this->scheme, $this->originUrl)) {
                        return parent::getContents($url);
                    }

                    if (!$this->io->isInteractive()) {
                        $this->attemptCloneFallback();

                        return new Response(['url' => 'dummy'], 200, [], 'null');
                    }
                    $this->io->writeError('<warning>Failed to download ' . $this->namespace . '/' . $this->repository . ':' . $e->getMessage() . '</warning>');
                    $gitLabUtil->authorizeOAuthInteractively($this->scheme, $this->originUrl, 'Your credentials are required to fetch private repository metadata (<info>'.$this->url.'</info>)');

                    return parent::getContents($url);

                case 403:
                    if (!$this->io->hasAuthentication($this->originUrl) && $gitLabUtil->authorizeOAuth($this->originUrl)) {
                        return parent::getContents($url);
                    }

                    if (!$this->io->isInteractive() && $fetchingRepoData) {
                        $this->attemptCloneFallback();

                        return new Response(['url' => 'dummy'], 200, [], 'null');
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
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        if (!Preg::isMatch(self::URL_REGEX, $url, $match)) {
            return false;
        }

        $scheme = $match['scheme'];
        $guessedDomain = $match['domain'] ?? (string) $match['domain2'];
        $urlParts = explode('/', $match['parts']);

        if (false === self::determineOrigin($config->get('gitlab-domains'), $guessedDomain, $urlParts, $match['port'])) {
            return false;
        }

        if ('https' === $scheme && !extension_loaded('openssl')) {
            $io->writeError('Skipping GitLab driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    /**
     * Gives back the loaded <gitlab-api>/projects/<owner>/<repo> result
     *
     * @return mixed[]|null
     */
    public function getRepoData(): ?array
    {
        $this->fetchProject();

        return $this->project;
    }

    protected function getNextPage(Response $response): ?string
    {
        $header = $response->getHeader('link');

        $links = explode(',', $header);
        foreach ($links as $link) {
            if (Preg::isMatchStrictGroups('{<(.+?)>; *rel="next"}', $link, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * @param  array<string> $configuredDomains
     * @param  array<string> $urlParts
     *
     * @return string|false
     */
    private static function determineOrigin(array $configuredDomains, string $guessedDomain, array &$urlParts, ?string $portNumber)
    {
        $guessedDomain = strtolower($guessedDomain);

        if (in_array($guessedDomain, $configuredDomains) || (null !== $portNumber && in_array($guessedDomain.':'.$portNumber, $configuredDomains))) {
            if (null !== $portNumber) {
                return $guessedDomain.':'.$portNumber;
            }

            return $guessedDomain;
        }

        if (null !== $portNumber) {
            $guessedDomain .= ':'.$portNumber;
        }

        while (null !== ($part = array_shift($urlParts))) {
            $guessedDomain .= '/' . $part;

            if (in_array($guessedDomain, $configuredDomains) || (null !== $portNumber && in_array(Preg::replace('{:\d+}', '', $guessedDomain), $configuredDomains))) {
                return $guessedDomain;
            }
        }

        return false;
    }
}
