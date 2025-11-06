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

use Composer\Cache;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Util\Forgejo;
use Composer\Util\ForgejoRepositoryData;
use Composer\Util\ForgejoUrl;
use Composer\Util\Http\Response;

class ForgejoDriver extends VcsDriver
{
    /** @var ForgejoUrl */
    private $forgejoUrl;
    /** @var ForgejoRepositoryData */
    private $repositoryData;

    /** @var ?GitDriver */
    protected $gitDriver = null;
    /** @var array<int|string, string> Map of tag name to identifier */
    private $tags;
    /** @var array<int|string, string> Map of branch name to identifier */
    private $branches;

    public function initialize(): void
    {
        $this->forgejoUrl = ForgejoUrl::create($this->url);
        $this->originUrl = $this->forgejoUrl->originUrl;

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->forgejoUrl->owner.'/'.$this->forgejoUrl->repository);
        $this->cache->setReadOnly($this->config->get('cache-read-only'));

        $this->fetchRepositoryData();
    }

    public function getFileContent(string $file, string $identifier): ?string
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getFileContent($file, $identifier);
        }

        $resource = $this->forgejoUrl->apiUrl.'/contents/' . $file . '?ref='.urlencode($identifier);
        $resource = $this->getContents($resource)->decodeJson();

        // The Forgejo contents API only returns files up to 1MB as base64 encoded files
        // larger files either need be fetched with a raw accept header or by using the git blob endpoint
        if ((!isset($resource['content']) || $resource['content'] === '') && $resource['encoding'] === 'none' && isset($resource['git_url'])) {
            $resource = $this->getContents($resource['git_url'])->decodeJson();
        }

        if (!isset($resource['content']) || $resource['encoding'] !== 'base64' || false === ($content = base64_decode($resource['content'], true))) {
            throw new \RuntimeException('Could not retrieve ' . $file . ' for '.$identifier);
        }

        return $content;
    }

    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getChangeDate($identifier);
        }

        $resource = $this->forgejoUrl->apiUrl.'/git/commits/'.urlencode($identifier).'?verification=false&files=false';
        $commit = $this->getContents($resource)->decodeJson();

        return new \DateTimeImmutable($commit['commit']['committer']['date']);
    }

    public function getRootIdentifier(): string
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getRootIdentifier();
        }

        return $this->repositoryData->defaultBranch;
    }

    public function getBranches(): array
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getBranches();
        }

        if (null === $this->branches) {
            $branches = [];
            $resource = $this->forgejoUrl->apiUrl.'/branches?per_page=100';

            do {
                $response = $this->getContents($resource);
                $branchData = $response->decodeJson();
                foreach ($branchData as $branch) {
                    $branches[$branch['name']] = $branch['commit']['id'];
                }

                $resource = $this->getNextPage($response);
            } while ($resource);

            $this->branches = $branches;
        }

        return $this->branches;
    }

    public function getTags(): array
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getTags();
        }
        if (null === $this->tags) {
            $tags = [];
            $resource = $this->forgejoUrl->apiUrl.'/tags?per_page=100';

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

    public function getDist(string $identifier): ?array
    {
        $url = $this->forgejoUrl->apiUrl.'/archive/'.$identifier.'.zip';

        return ['type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => ''];
    }

    public function getComposerInformation(string $identifier): ?array
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getComposerInformation($identifier);
        }

        if (!isset($this->infoCache[$identifier])) {
            if ($this->shouldCache($identifier) && false !== ($res = $this->cache->read($identifier))) {
                $composer = JsonFile::parseJson($res);
            } else {
                $composer = $this->getBaseComposerInformation($identifier);

                if ($this->shouldCache($identifier)) {
                    $this->cache->write($identifier, JsonFile::encode($composer, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
                }
            }

            if ($composer !== null) {
                // specials for forgejo
                if (isset($composer['support']) && !is_array($composer['support'])) {
                    $composer['support'] = [];
                }
                if (!isset($composer['support']['source'])) {
                    if (false !== ($label = array_search($identifier, $this->getTags(), true))) {
                        $composer['support']['source'] = $this->repositoryData->htmlUrl.'/tag/' . $label;
                    } elseif (false !== ($label = array_search($identifier, $this->getBranches(), true))) {
                        $composer['support']['source'] = $this->repositoryData->htmlUrl.'/branch/'.$label;
                    } else {
                        $composer['support']['source'] = $this->repositoryData->htmlUrl.'/commit/'.$identifier;
                    }
                }
                if (!isset($composer['support']['issues']) && $this->repositoryData->hasIssues) {
                    $composer['support']['issues'] = $this->repositoryData->htmlUrl.'/issues';
                }
                if (!isset($composer['abandoned']) && $this->repositoryData->isArchived) {
                    $composer['abandoned'] = true;
                }
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    public function getSource(string $identifier): array
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getSource($identifier);
        }

        return ['type' => 'git', 'url' => $this->getUrl(), 'reference' => $identifier];
    }

    public function getUrl(): string
    {
        if ($this->gitDriver !== null) {
            return $this->gitDriver->getUrl();
        }

        return $this->repositoryData->isPrivate ? $this->repositoryData->sshUrl : $this->repositoryData->httpCloneUrl;
    }

    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        $forgejoUrl = ForgejoUrl::tryFrom($url);
        if ($forgejoUrl === null) {
            return false;
        }

        if (!in_array(strtolower($forgejoUrl->originUrl), $config->get('forgejo-domains'), true)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            $io->writeError('Skipping Forgejo driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
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

    private function fetchRepositoryData(): void
    {
        if ($this->repositoryData !== null) {
            return;
        }

        $data = $this->getContents($this->forgejoUrl->apiUrl, true)->decodeJson();

        if (null === $data && null !== $this->gitDriver) {
            return;
        }

        $this->repositoryData = ForgejoRepositoryData::fromRemoteData($data);
    }

    protected function getNextPage(Response $response): ?string
    {
        $header = $response->getHeader('link');
        if ($header === null) {
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

    protected function getContents(string $url, bool $fetchingRepoData = false): Response
    {
        $forgejo = new Forgejo($this->io, $this->config, $this->httpDownloader);

        try {
            return parent::getContents($url);
        } catch (TransportException $e) {
            switch ($e->getCode()) {
                case 401:
                case 403:
                case 404:
                case 429:
                    if (!$fetchingRepoData) {
                        throw $e;
                    }

                    if (!$this->io->isInteractive()) {
                        $this->attemptCloneFallback();

                        return new Response(['url' => 'dummy'], 200, [], 'null');
                    }

                    if (
                        !$this->io->hasAuthentication($this->originUrl) &&
                        $forgejo->authorizeOAuthInteractively($this->forgejoUrl->originUrl, $e->getCode() === 429 ? 'API limit exhausted. Enter your Forgejo credentials to get a larger API limit (<info>'.$this->url.'</info>)' : null)
                    ) {
                        return parent::getContents($url);
                    }

                    throw $e;
                default:
                    throw $e;
            }
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
        try {
            // If this repository may be private (hard to say for sure,
            // Forgejo returns 404 for private repositories) and we
            // cannot ask for authentication credentials (because we
            // are not interactive) then we fallback to GitDriver.
            $this->setupGitDriver($this->forgejoUrl->generateSshUrl());

            return true;
        } catch (\RuntimeException $e) {
            $this->gitDriver = null;

            $this->io->writeError('<error>Failed to clone the '.$this->forgejoUrl->generateSshUrl().' repository, try running in interactive mode so that you can enter your Forgejo credentials</error>');
            throw $e;
        }
    }
}
