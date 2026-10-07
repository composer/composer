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
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\GitHub;
use Composer\Util\Http\Response;
use Composer\Util\Url;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitHubDriver extends VcsDriver
{
    /** @var string */
    protected $owner;
    /** @var string */
    protected $repository;
    /** @var array<int|string, string> Map of tag name to identifier */
    protected $tags;
    /** @var array<int|string, string> Map of branch name to identifier */
    protected $branches;
    /** @var string */
    protected $rootIdentifier;
    /** @var mixed[] */
    protected $repoData;
    /** @var bool */
    protected $hasIssues = false;
    /** @var bool */
    protected $isPrivate = false;
    /** @var bool */
    private $isArchived = false;
    /** @var array<int, array{type: string, url: string}>|false|null */
    private $fundingInfo;
    /** @var bool */
    private $allowGitFallback = true;

    /**
     * Git Driver
     *
     * @var ?GitDriver
     */
    protected $gitDriver = null;
    /** @var bool */
    private $prefetchStarted = false;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        if (!$this->parseUrl()) {
            throw new \InvalidArgumentException(sprintf('The GitHub repository URL %s is invalid.', Url::sanitize($this->url)));
        }
        $this->initializeCache();

        if (isset($this->repoConfig['allow-git-fallback']) && $this->repoConfig['allow-git-fallback'] === false) {
            $this->allowGitFallback = false;
        }

        if ($this->config->get('use-github-api') === false || (isset($this->repoConfig['no-api']) && $this->repoConfig['no-api'])) {
            $this->setupGitDriver($this->url);

            return;
        }

        $this->fetchRootIdentifier();
    }

    /**
     * @internal
     */
    public function prefetch(): void
    {
        $maxJobs = Platform::getEnv('COMPOSER_MAX_PARALLEL_HTTP');
        if ($this->prefetchStarted || $this->gitDriver !== null || !HttpDownloader::isCurlEnabled()
            || (bool) Platform::getEnv('COMPOSER_DISABLE_NETWORK')
            || (is_numeric($maxJobs) && (int) $maxJobs <= 1)
            || $this->config->get('use-github-api') === false || (bool) ($this->repoConfig['no-api'] ?? false)
        ) {
            return;
        }
        if ($this->repoData === null && !$this->parseUrl()) {
            return;
        }

        $this->prefetchStarted = true;
        $repoUrl = $this->getApiUrl().'/repos/'.$this->owner.'/'.$this->repository;
        $repoData = $this->repoData;
        $branches = null;
        if ($this->repoData === null) {
            $this->prefetchContents($repoUrl, function (Response $response) use (&$repoData, &$branches): void {
                $repoData = $response->decodeJson();
                $this->prefetchRootPackage($repoData, $branches);
            });
        }
        $this->prefetchContents($repoUrl.'/tags?per_page=100');
        $this->prefetchContents($repoUrl.'/git/refs/heads?per_page=100', function (Response $response) use (&$repoData, &$branches): void {
            $branches = $response->decodeJson();
            $this->prefetchRootPackage($repoData, $branches);
        });
    }

    /**
     * @phpstan-assert !null $this->cache
     */
    private function initializeCache(): void
    {
        if ($this->cache === null) {
            $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->owner.'/'.$this->repository);
            $this->cache->setReadOnly($this->config->get('cache-read-only'));
        }
    }

    /**
     * @param array<string, mixed>|null $repoData
     * @param array<array{ref: string, object: array{sha: string}}>|null $branches
     */
    private function prefetchRootPackage(?array $repoData, ?array $branches): void
    {
        if ($repoData === null || $branches === null) {
            return;
        }

        $branch = $repoData['default_branch'] ?? $repoData['master_branch'] ?? 'master';
        foreach ($branches as $ref) {
            if ($ref['ref'] !== 'refs/heads/'.$branch) {
                continue;
            }

            $identifier = $ref['object']['sha'];
            if (!Preg::isMatch('{^[a-f0-9]{40}$}iD', $identifier)) {
                return;
            }
            $repoUrl = $this->getApiUrl().'/repos/'.$repoData['owner']['login'].'/'.$repoData['name'];
            $this->initializeCache();
            $contents = $this->cache->read($identifier);
            if ($contents !== false) {
                $composer = JsonFile::parseJson($contents);
                if ($composer !== null && !isset($composer['funding'])) {
                    $this->prefetchFunding($repoUrl, $repoData['owner']['login']);
                }
            } else {
                $this->prefetchContents($repoUrl.'/contents/composer.json?ref='.$identifier, function (Response $response) use ($repoUrl, $repoData, $identifier): void {
                    $file = $response->decodeJson();
                    if ($file['encoding'] !== 'base64') {
                        return;
                    }
                    $composer = JsonFile::parseJson((string) base64_decode($file['content'], true));
                    if ($composer === null || $composer === []) {
                        return;
                    }
                    if (!isset($composer['time']) || $composer['time'] === '') {
                        $this->prefetchContents($repoUrl.'/commits/'.$identifier);
                    }
                    if (!isset($composer['funding'])) {
                        $this->prefetchFunding($repoUrl, $repoData['owner']['login']);
                    }
                });
            }

            return;
        }
    }

    private function prefetchFunding(string $repoUrl, string $owner): void
    {
        if ($this->originUrl !== 'github.com') {
            return;
        }

        $this->httpDownloader->addShared($repoUrl.'/contents/.github/FUNDING.yml', ['retry-auth-failure' => false])->then(null, function (\Throwable $e) use ($owner): void {
            $this->httpDownloader->addShared($this->getApiUrl().'/repos/'.$owner.'/.github/contents/FUNDING.yml', ['retry-auth-failure' => false])->then(null, static function (\Throwable $e): void {
            });
        });
    }

    private function parseUrl(): bool
    {
        if (!Preg::isMatch('#^(?:(?:https?|git)://([^/]+)/|git@([^:]+):/?)([^/]+)/([^/]+?)(?:\.git|/)?$#', $this->url, $match)) {
            return false;
        }

        $this->owner = $match[3];
        $this->repository = $match[4];
        $this->originUrl = strtolower($match[1] ?? (string) $match[2]);
        if ($this->originUrl === 'www.github.com') {
            $this->originUrl = 'github.com';
        }

        return true;
    }

    public function getRepositoryUrl(): string
    {
        return 'https://'.$this->originUrl.'/'.$this->owner.'/'.$this->repository;
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier(): string
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getRootIdentifier();
        }

        return $this->rootIdentifier;
    }

    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getUrl();
        }

        return 'https://' . $this->originUrl . '/'.$this->owner.'/'.$this->repository.'.git';
    }

    protected function getApiUrl(): string
    {
        if ('github.com' === $this->originUrl) {
            $apiUrl = 'api.github.com';
        } else {
            $apiUrl = $this->originUrl . '/api/v3';
        }

        return 'https://' . $apiUrl;
    }

    protected function getPrefetchKey(string $url): string
    {
        // Responses belong to this driver even when GitHub redirects a renamed repository.
        return Preg::replace('{^'.preg_quote($this->getApiUrl(), '{').'/repos/[^/]+/[^/]+(?=/|$)}', '', $url);
    }

    /**
     * @inheritDoc
     */
    public function getSource(string $identifier): array
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getSource($identifier);
        }
        if ($this->isPrivate) {
            // Private GitHub repositories should be accessed using the
            // SSH version of the URL.
            $url = $this->generateSshUrl();
        } else {
            $url = $this->getUrl();
        }

        return ['type' => 'git', 'url' => $url, 'reference' => $identifier];
    }

    /**
     * @inheritDoc
     */
    public function getDist(string $identifier): ?array
    {
        $url = $this->getApiUrl() . '/repos/'.$this->owner.'/'.$this->repository.'/zipball/'.$identifier;

        return ['type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => ''];
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
            $cacheIdentifier = $identifier;
            if ($identifier === $this->rootIdentifier) {
                $cacheIdentifier = $this->getBranches()[$identifier] ?? $identifier;
            }
            if ($this->shouldCache($cacheIdentifier) && $res = $this->cache->read($cacheIdentifier)) {
                $composer = JsonFile::parseJson($res);
            } else {
                $composer = $this->getBaseComposerInformation($cacheIdentifier);

                if ($this->shouldCache($cacheIdentifier)) {
                    $this->cache->write($cacheIdentifier, JsonFile::encode($composer, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
                }
            }

            if ($composer !== null) {
                // specials for github
                if (isset($composer['support']) && !is_array($composer['support'])) {
                    $composer['support'] = [];
                }
                if (!isset($composer['support']['source'])) {
                    $label = array_search($identifier, $this->getTags()) ?: array_search($identifier, $this->getBranches()) ?: $identifier;
                    $composer['support']['source'] = sprintf('https://%s/%s/%s/tree/%s', $this->originUrl, $this->owner, $this->repository, $label);
                }
                if (!isset($composer['support']['issues']) && $this->hasIssues) {
                    $composer['support']['issues'] = sprintf('https://%s/%s/%s/issues', $this->originUrl, $this->owner, $this->repository);
                }
                if (!isset($composer['abandoned']) && $this->isArchived) {
                    $composer['abandoned'] = true;
                }
                if (!isset($composer['funding']) && $funding = $this->getFundingInfo()) {
                    $composer['funding'] = $funding;
                }
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * @return array<int, array{type: string, url: string}>|false
     */
    private function getFundingInfo()
    {
        if (null !== $this->fundingInfo) {
            return $this->fundingInfo;
        }

        if ($this->originUrl !== 'github.com') {
            return $this->fundingInfo = false;
        }

        foreach ([$this->getApiUrl() . '/repos/'.$this->owner.'/'.$this->repository.'/contents/.github/FUNDING.yml', $this->getApiUrl() . '/repos/'.$this->owner.'/.github/contents/FUNDING.yml'] as $file) {
            try {
                $response = $this->httpDownloader->getShared($file, [
                    'retry-auth-failure' => false,
                ])->decodeJson();
            } catch (TransportException $e) {
                continue;
            }
            if (empty($response['content']) || $response['encoding'] !== 'base64' || !($funding = base64_decode($response['content']))) {
                continue;
            }
            break;
        }
        if (empty($funding)) {
            return $this->fundingInfo = false;
        }

        $result = [];
        $key = null;
        foreach (Preg::split('{\r?\n}', $funding) as $line) {
            $line = trim($line);
            if (Preg::isMatchStrictGroups('{^(\w+)\s*:\s*(.+)$}', $line, $match)) {
                if ($match[2] === '[') {
                    $key = $match[1];
                    continue;
                }
                if (Preg::isMatchStrictGroups('{^\[(.*?)\](?:\s*#.*)?$}', $match[2], $match2)) {
                    foreach (array_map('trim', Preg::split('{[\'"]?\s*,\s*[\'"]?}', $match2[1])) as $item) {
                        $result[] = ['type' => $match[1], 'url' => trim($item, '"\' ')];
                    }
                } elseif (Preg::isMatchStrictGroups('{^([^#].*?)(?:\s+#.*)?$}', $match[2], $match2)) {
                    $result[] = ['type' => $match[1], 'url' => trim($match2[1], '"\' ')];
                }
                $key = null;
            } elseif (Preg::isMatchStrictGroups('{^(\w+)\s*:\s*#\s*$}', $line, $match)) {
                $key = $match[1];
            } elseif ($key !== null && (
                Preg::isMatchStrictGroups('{^-\s*(.+)(?:\s+#.*)?$}', $line, $match)
                || Preg::isMatchStrictGroups('{^(.+),(?:\s*#.*)?$}', $line, $match)
            )) {
                $result[] = ['type' => $key, 'url' => trim($match[1], '"\' ')];
            } elseif ($key !== null && $line === ']') {
                $key = null;
            }
        }

        foreach ($result as $key => $item) {
            switch ($item['type']) {
                case 'community_bridge':
                    $result[$key]['url'] = 'https://funding.communitybridge.org/projects/' . basename($item['url']);
                    break;
                case 'github':
                    $result[$key]['url'] = 'https://github.com/' . basename($item['url']);
                    break;
                case 'issuehunt':
                    $result[$key]['url'] = 'https://issuehunt.io/r/' . $item['url'];
                    break;
                case 'ko_fi':
                    $result[$key]['url'] = 'https://ko-fi.com/' . basename($item['url']);
                    break;
                case 'liberapay':
                    $result[$key]['url'] = 'https://liberapay.com/' . basename($item['url']);
                    break;
                case 'open_collective':
                    $result[$key]['url'] = 'https://opencollective.com/' . basename($item['url']);
                    break;
                case 'patreon':
                    $result[$key]['url'] = 'https://www.patreon.com/' . basename($item['url']);
                    break;
                case 'tidelift':
                    $result[$key]['url'] = 'https://tidelift.com/funding/github/' . $item['url'];
                    break;
                case 'polar':
                    $result[$key]['url'] = 'https://polar.sh/' . basename($item['url']);
                    break;
                case 'buy_me_a_coffee':
                    $result[$key]['url'] = 'https://www.buymeacoffee.com/' . basename($item['url']);
                    break;
                case 'thanks_dev':
                    $result[$key]['url'] = 'https://thanks.dev/' . $item['url'];
                    break;
                case 'otechie':
                    $result[$key]['url'] = 'https://otechie.com/' . basename($item['url']);
                    break;
                case 'custom':
                    $bits = parse_url($item['url']);
                    if ($bits === false) {
                        unset($result[$key]);
                        break;
                    }

                    if (!array_key_exists('scheme', $bits) && !array_key_exists('host', $bits)) {
                        if (Preg::isMatch('{^[a-z0-9-]++\.[a-z]{2,3}$}', $item['url'])) {
                            $result[$key]['url'] = 'https://'.$item['url'];
                            break;
                        }

                        $this->io->writeError('<warning>Funding URL '.$item['url'].' not in a supported format.</warning>');
                        unset($result[$key]);
                        break;
                    }
                    break;
            }
        }

        return $this->fundingInfo = $result;
    }

    /**
     * @inheritDoc
     */
    public function getFileContent(string $file, string $identifier): ?string
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getFileContent($file, $identifier);
        }

        $resource = $this->getApiUrl() . '/repos/'.$this->owner.'/'.$this->repository.'/contents/' . $file . '?ref='.urlencode($identifier);
        $resource = $this->getContents($resource)->decodeJson();

        // The GitHub contents API only returns files up to 1MB as base64 encoded files
        // larger files either need be fetched with a raw accept header or by using the git blob endpoint
        if ((!isset($resource['content']) || $resource['content'] === '') && $resource['encoding'] === 'none' && isset($resource['git_url'])) {
            $resource = $this->getContents($resource['git_url'])->decodeJson();
        }

        if (!isset($resource['content']) || $resource['encoding'] !== 'base64' || false === ($content = base64_decode($resource['content']))) {
            throw new \RuntimeException('Could not retrieve ' . $file . ' for '.$identifier);
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

        $resource = $this->getApiUrl() . '/repos/'.$this->owner.'/'.$this->repository.'/commits/'.urlencode($identifier);
        $commit = $this->getContents($resource)->decodeJson();

        return new \DateTimeImmutable($commit['commit']['committer']['date']);
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
            $tags = [];
            $resource = $this->getApiUrl() . '/repos/'.$this->owner.'/'.$this->repository.'/tags?per_page=100';

            do {
                $response = $this->getContents($resource);
                $tagsData = $response->decodeJson();
                foreach ($tagsData as $tag) {
                    $tags[$tag['name']] = $tag['commit']['sha'];
                }

                $resource = $this->getNextPage($response);
            } while ($resource);

            $this->tags = $tags;
        }

        return $this->tags;
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
            $branches = [];
            $resource = $this->getApiUrl() . '/repos/'.$this->owner.'/'.$this->repository.'/git/refs/heads?per_page=100';

            do {
                $response = $this->getContents($resource);
                $branchData = $response->decodeJson();
                foreach ($branchData as $branch) {
                    $name = substr($branch['ref'], 11);
                    if ($name !== 'gh-pages') {
                        $branches[$name] = $branch['object']['sha'];
                    }
                }

                $resource = $this->getNextPage($response);
            } while ($resource);

            $this->branches = $branches;
        }

        return $this->branches;
    }

    /**
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        if (!Preg::isMatch('#^((?:https?|git)://([^/]+)/|git@([^:]+):/?)([^/]+)/([^/]+?)(?:\.git|/)?$#', $url, $matches)) {
            return false;
        }

        $originUrl = $matches[2] ?? (string) $matches[3];
        if (!in_array(strtolower(Preg::replace('{^www\.}i', '', $originUrl)), $config->get('github-domains'))) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            $io->writeError('Skipping GitHub driver for '.Url::sanitize($url).' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    /**
     * Gives back the loaded <github-api>/repos/<owner>/<repo> result
     *
     * @return mixed[]|null
     */
    public function getRepoData(): ?array
    {
        $this->fetchRootIdentifier();

        return $this->repoData;
    }

    /**
     * Generate an SSH URL
     */
    protected function generateSshUrl(): string
    {
        if (false !== strpos($this->originUrl, ':')) {
            return 'ssh://git@' . $this->originUrl . '/'.$this->owner.'/'.$this->repository.'.git';
        }

        return 'git@' . $this->originUrl . ':'.$this->owner.'/'.$this->repository.'.git';
    }

    /**
     * @inheritDoc
     */
    protected function getContents(string $url, bool $fetchingRepoData = false): Response
    {
        try {
            return parent::getContents($url);
        } catch (TransportException $e) {
            $gitHubUtil = new GitHub($this->io, $this->config, $this->process, $this->httpDownloader);

            switch ($e->getCode()) {
                case 401:
                case 404:
                    // try to authorize only if we are fetching the main /repos/foo/bar data, otherwise it must be a real 404
                    if (!$fetchingRepoData) {
                        throw $e;
                    }

                    if ($gitHubUtil->authorizeOAuth($this->originUrl)) {
                        return parent::getContents($url);
                    }

                    if (!$this->io->isInteractive()) {
                        $this->attemptCloneFallback($e);

                        return new Response(['url' => 'dummy'], 200, [], 'null');
                    }

                    $scopesIssued = [];
                    $scopesNeeded = [];
                    if ($headers = $e->getHeaders()) {
                        if ($scopes = Response::findHeaderValue($headers, 'X-OAuth-Scopes')) {
                            $scopesIssued = explode(' ', $scopes);
                        }
                        if ($scopes = Response::findHeaderValue($headers, 'X-Accepted-OAuth-Scopes')) {
                            $scopesNeeded = explode(' ', $scopes);
                        }
                    }
                    $scopesFailed = array_diff($scopesNeeded, $scopesIssued);
                    // non-authenticated requests get no scopesNeeded, so ask for credentials
                    // authenticated requests which failed some scopes should ask for new credentials too
                    if (!$headers || !count($scopesNeeded) || count($scopesFailed)) {
                        $gitHubUtil->authorizeOAuthInteractively($this->originUrl, 'Your GitHub credentials are required to fetch private repository metadata (<info>'.Url::sanitize($this->url).'</info>)');
                    }

                    return parent::getContents($url);

                case 403:
                    if (!$this->io->hasAuthentication($this->originUrl) && $gitHubUtil->authorizeOAuth($this->originUrl)) {
                        return parent::getContents($url);
                    }

                    $rateLimited = $gitHubUtil->isRateLimited((array) $e->getHeaders());

                    if (!$this->io->isInteractive() && $fetchingRepoData) {
                        try {
                            $this->attemptCloneFallback($e);
                        } catch (\RuntimeException $cloneException) {
                            // The clone was the last way to get the metadata, so the rate limit is
                            // the reason nothing could be fetched at all and has to be reported.
                            // Otherwise it stays hidden behind whatever made the clone fail.
                            if ($rateLimited) {
                                $this->writeRateLimitError($gitHubUtil, $e);
                            }

                            throw $cloneException;
                        }

                        return new Response(['url' => 'dummy'], 200, [], 'null');
                    }

                    if (!$this->io->hasAuthentication($this->originUrl)) {
                        if (!$this->io->isInteractive()) {
                            $this->writeRateLimitError($gitHubUtil, $e);
                            throw $e;
                        }

                        $gitHubUtil->authorizeOAuthInteractively($this->originUrl, 'API limit exhausted. Enter your GitHub credentials to get a larger API limit (<info>'.Url::sanitize($this->url).'</info>)');

                        return parent::getContents($url);
                    }

                    if ($rateLimited) {
                        $this->writeRateLimitError($gitHubUtil, $e);
                    }

                    throw $e;

                default:
                    throw $e;
            }
        }
    }

    /**
     * Fetch root identifier from GitHub
     *
     * @throws TransportException
     */
    protected function fetchRootIdentifier(): void
    {
        if ($this->repoData) {
            return;
        }

        $repoDataUrl = $this->getApiUrl() . '/repos/'.$this->owner.'/'.$this->repository;

        try {
            $this->repoData = $this->getContents($repoDataUrl, true)->decodeJson();
        } catch (TransportException $e) {
            if ($e->getCode() === 499) {
                $this->attemptCloneFallback($e);
            } else {
                throw $e;
            }
        }
        if (null === $this->repoData && null !== $this->gitDriver) {
            return;
        }

        $this->owner = $this->repoData['owner']['login'];
        $this->repository = $this->repoData['name'];

        $this->isPrivate = !empty($this->repoData['private']);
        if (isset($this->repoData['default_branch'])) {
            $this->rootIdentifier = $this->repoData['default_branch'];
        } elseif (isset($this->repoData['master_branch'])) {
            $this->rootIdentifier = $this->repoData['master_branch'];
        } else {
            $this->rootIdentifier = 'master';
        }
        $this->hasIssues = !empty($this->repoData['has_issues']);
        $this->isArchived = !empty($this->repoData['archived']);
    }

    /**
     * Tells the user that the GitHub API limit is what stopped the request.
     */
    private function writeRateLimitError(GitHub $gitHubUtil, TransportException $e): void
    {
        if (!$this->io->hasAuthentication($this->originUrl)) {
            $this->io->writeError('<error>GitHub API limit exhausted. Failed to get metadata for the '.Url::sanitize($this->url).' repository, try running in interactive mode so that you can enter your GitHub credentials to increase the API limit</error>');

            return;
        }

        $rateLimit = $gitHubUtil->getRateLimit($e->getHeaders());
        $this->io->writeError(sprintf(
            '<error>GitHub API limit (%d calls/hr) is exhausted. You are already authorized so you have to wait until %s before doing more requests</error>',
            $rateLimit['limit'],
            $rateLimit['reset']
        ));
    }

    /**
     * @phpstan-impure
     *
     * @return true
     * @throws \RuntimeException
     */
    protected function attemptCloneFallback(?\Throwable $e = null): bool
    {
        if (!$this->allowGitFallback) {
            throw new \RuntimeException('Fallback to git driver disabled', 0, $e);
        }

        $this->isPrivate = true;

        try {
            // If this repository may be private (hard to say for sure,
            // GitHub returns 404 for private repositories) and we
            // cannot ask for authentication credentials (because we
            // are not interactive) then we fallback to GitDriver.
            $this->setupGitDriver($this->generateSshUrl());

            return true;
        } catch (\RuntimeException $e) {
            $this->gitDriver = null;

            $this->io->writeError('<error>Failed to clone the '.$this->generateSshUrl().' repository, try running in interactive mode so that you can enter your GitHub credentials</error>');
            throw $e;
        }
    }

    protected function setupGitDriver(string $url): void
    {
        if (!$this->allowGitFallback) {
            throw new \RuntimeException('Fallback to git driver disabled');
        }
        $this->gitDriver = new GitDriver(
            ['url' => $url],
            $this->io,
            $this->config,
            $this->httpDownloader,
            $this->process
        );
        $this->gitDriver->initialize();
    }

    protected function getNextPage(Response $response): ?string
    {
        $header = $response->getHeader('link');
        if (!$header) {
            return null;
        }

        $links = explode(',', $header);
        foreach ($links as $link) {
            if (Preg::isMatch('{<(.+?)>; *rel="next"}', $link, $match)) {
                return $match[1];
            }
        }

        return null;
    }
}
