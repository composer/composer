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
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\Url;
use Composer\Util\Svn as SvnUtil;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Till Klampaeckel <till@php.net>
 */
class SvnDriver extends VcsDriver
{
    /** @var string */
    protected $baseUrl;
    /** @var array<int|string, string> Map of tag name to identifier */
    protected $tags;
    /** @var array<int|string, string> Map of branch name to identifier */
    protected $branches;
    /** @var ?string */
    protected $rootIdentifier;

    /** @var string|false */
    protected $trunkPath = 'trunk';
    /** @var string */
    protected $branchesPath = 'branches';
    /** @var string */
    protected $tagsPath = 'tags';
    /** @var string */
    protected $packagePath = '';
    /** @var bool */
    protected $cacheCredentials = true;

    /**
     * @var \Composer\Util\Svn
     */
    private $util;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        $this->url = $this->baseUrl = rtrim(self::normalizeUrl($this->url), '/');

        SvnUtil::cleanEnv();

        if (isset($this->repoConfig['trunk-path'])) {
            $this->trunkPath = $this->repoConfig['trunk-path'];
        }
        if (isset($this->repoConfig['branches-path'])) {
            $this->branchesPath = $this->repoConfig['branches-path'];
        }
        if (isset($this->repoConfig['tags-path'])) {
            $this->tagsPath = $this->repoConfig['tags-path'];
        }
        if (array_key_exists('svn-cache-credentials', $this->repoConfig)) {
            $this->cacheCredentials = (bool) $this->repoConfig['svn-cache-credentials'];
        }
        if (isset($this->repoConfig['package-path'])) {
            $this->packagePath = '/' . trim($this->repoConfig['package-path'], '/');
        }

        if (false !== ($pos = strrpos($this->url, '/' . $this->trunkPath))) {
            $this->baseUrl = substr($this->url, 0, $pos);
        }

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.Preg::replace('{[^a-z0-9.]}i', '-', Url::sanitize($this->baseUrl)));
        $this->cache->setReadOnly($this->config->get('cache-read-only'));

        $this->getBranches();
        $this->getTags();
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier(): string
    {
        return $this->rootIdentifier ?: $this->trunkPath;
    }

    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @inheritDoc
     */
    public function getSource(string $identifier): array
    {
        return ['type' => 'svn', 'url' => $this->baseUrl, 'reference' => $identifier];
    }

    /**
     * @inheritDoc
     */
    public function getDist(string $identifier): ?array
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    protected function shouldCache(string $identifier): bool
    {
        return $this->cache && Preg::isMatch('{@\d+$}', $identifier);
    }

    /**
     * @inheritDoc
     */
    public function getComposerInformation(string $identifier): ?array
    {
        if (!isset($this->infoCache[$identifier])) {
            if ($this->shouldCache($identifier) && $res = $this->cache->read($identifier.'.json')) {
                // old cache files had '' stored instead of null due to af3783b5f40bae32a23e353eaf0a00c9b8ce82e2, so we make sure here that we always return null or array
                // and fix outdated invalid cache files
                if ($res === '""') {
                    $res = 'null';
                    $this->cache->write($identifier.'.json', json_encode(null));
                }

                return $this->infoCache[$identifier] = JsonFile::parseJson($res);
            }

            try {
                $composer = $this->getBaseComposerInformation($identifier);
            } catch (TransportException $e) {
                $message = $e->getMessage();
                if (stripos($message, 'path not found') === false && stripos($message, 'svn: warning: W160013') === false) {
                    throw $e;
                }
                // remember a not-existent composer.json
                $composer = null;
            }

            if ($this->shouldCache($identifier)) {
                $this->cache->write($identifier.'.json', json_encode($composer));
            }

            $this->infoCache[$identifier] = $composer;
        }

        // old cache files had '' stored instead of null due to af3783b5f40bae32a23e353eaf0a00c9b8ce82e2, so we make sure here that we always return null or array
        if (!is_array($this->infoCache[$identifier])) {
            return null;
        }

        return $this->infoCache[$identifier];
    }

    public function getFileContent(string $file, string $identifier): ?string
    {
        $identifier = '/' . trim($identifier, '/') . '/';

        if (Preg::isMatch('{^(.+?)(@\d+)?/$}', $identifier, $match) && $match[2] !== null) {
            $path = $match[1];
            $rev = $match[2];
        } else {
            $path = $identifier;
            $rev = '';
        }

        try {
            $resource = $path.$file;
            $output = $this->execute(['svn', 'cat'], $this->baseUrl . $resource . $rev);
            if ('' === trim($output)) {
                return null;
            }
        } catch (\RuntimeException $e) {
            throw new TransportException($e->getMessage());
        }

        return $output;
    }

    /**
     * @inheritDoc
     */
    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        $identifier = '/' . trim($identifier, '/') . '/';

        if (Preg::isMatch('{^(.+?)(@\d+)?/$}', $identifier, $match) && null !== $match[2]) {
            $path = $match[1];
            $rev = $match[2];
        } else {
            $path = $identifier;
            $rev = '';
        }

        $output = $this->execute(['svn', 'info'], $this->baseUrl . $path . $rev);
        foreach ($this->process->splitLines($output) as $line) {
            if ($line !== '' && Preg::isMatchStrictGroups('{^Last Changed Date: ([^(]+)}', $line, $match)) {
                return new \DateTimeImmutable($match[1], new \DateTimeZone('UTC'));
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getTags(): array
    {
        if (null === $this->tags) {
            $tags = [];

            if ($this->tagsPath !== false) {
                $output = $this->execute(['svn', 'ls', '--verbose'], $this->baseUrl . '/' . $this->tagsPath);
                if ($output !== '') {
                    $lastRev = 0;
                    foreach ($this->process->splitLines($output) as $line) {
                        $line = trim($line);
                        if ($line !== '' && Preg::isMatch('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                            if ($match[2] === './') {
                                $lastRev = (int) $match[1];
                            } else {
                                $tags[rtrim($match[2], '/')] = $this->buildIdentifier(
                                    '/' . $this->tagsPath . '/' . $match[2],
                                    max($lastRev, (int) $match[1])
                                );
                            }
                        }
                    }
                }
            }

            $this->tags = $tags;
        }

        return $this->tags;
    }

    /**
     * @inheritDoc
     */
    public function getBranches(): array
    {
        if (null === $this->branches) {
            $branches = [];

            if (false === $this->trunkPath) {
                $trunkParent = $this->baseUrl . '/';
            } else {
                $trunkParent = $this->baseUrl . '/' . $this->trunkPath;
            }

            $output = $this->execute(['svn', 'ls', '--verbose'], $trunkParent);
            if ($output !== '') {
                foreach ($this->process->splitLines($output) as $line) {
                    $line = trim($line);
                    if ($line !== '' && Preg::isMatch('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                        if ($match[2] === './') {
                            $branches['trunk'] = $this->buildIdentifier(
                                '/' . $this->trunkPath,
                                (int) $match[1]
                            );
                            $this->rootIdentifier = $branches['trunk'];
                            break;
                        }
                    }
                }
            }
            unset($output);

            if ($this->branchesPath !== false) {
                $output = $this->execute(['svn', 'ls', '--verbose'], $this->baseUrl . '/' . $this->branchesPath);
                if ($output !== '') {
                    $lastRev = 0;
                    foreach ($this->process->splitLines(trim($output)) as $line) {
                        $line = trim($line);
                        if ($line !== '' && Preg::isMatch('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                            if ($match[2] === './') {
                                $lastRev = (int) $match[1];
                            } else {
                                $branches[rtrim($match[2], '/')] = $this->buildIdentifier(
                                    '/' . $this->branchesPath . '/' . $match[2],
                                    max($lastRev, (int) $match[1])
                                );
                            }
                        }
                    }
                }
            }

            $this->branches = $branches;
        }

        return $this->branches;
    }

    /**
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        $url = self::normalizeUrl($url);
        if (Preg::isMatch('#(^svn://|^svn\+ssh://|svn\.)#i', $url)) {
            return true;
        }

        // proceed with deep check for local urls since they are fast to process
        if (!$deep && !Filesystem::isLocalPath($url)) {
            return false;
        }

        $process = new ProcessExecutor($io);
        $exit = $process->execute(['svn', 'info', '--non-interactive', '--', $url], $ignoredOutput);

        if ($exit === 0) {
            // This is definitely a Subversion repository.
            return true;
        }

        // Subversion client 1.7 and older
        if (false !== stripos($process->getErrorOutput(), 'authorization failed:')) {
            // This is likely a remote Subversion repository that requires
            // authentication. We will handle actual authentication later.
            return true;
        }

        // Subversion client 1.8 and newer
        if (false !== stripos($process->getErrorOutput(), 'Authentication failed')) {
            // This is likely a remote Subversion or newer repository that requires
            // authentication. We will handle actual authentication later.
            return true;
        }

        return false;
    }

    /**
     * An absolute path (leading '/') is converted to a file:// url.
     */
    protected static function normalizeUrl(string $url): string
    {
        $fs = new Filesystem();
        if ($fs->isAbsolutePath($url) && !Filesystem::isStreamWrapperPath($url)) {
            return 'file://' . strtr($url, '\\', '/');
        }

        return $url;
    }

    /**
     * Execute an SVN command and try to fix up the process with credentials
     * if necessary.
     *
     * @param  non-empty-list<string> $command The svn command to run.
     * @param  string            $url     The SVN URL.
     * @throws \RuntimeException
     */
    protected function execute(array $command, string $url): string
    {
        if (null === $this->util) {
            $this->util = new SvnUtil($this->baseUrl, $this->io, $this->config, $this->process);
            $this->util->setCacheCredentials($this->cacheCredentials);
        }

        try {
            return $this->util->execute($command, $url);
        } catch (\RuntimeException $e) {
            if (null === $this->util->binaryVersion()) {
                throw new \RuntimeException('Failed to load '.$this->url.', svn was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
            }

            throw new \RuntimeException(
                'Repository '.$this->url.' could not be processed, '.$e->getMessage()
            );
        }
    }

    /**
     * Build the identifier respecting "package-path" config option
     *
     * @param string $baseDir  The path to trunk/branch/tag
     * @param int $revision The revision mark to add to identifier
     */
    protected function buildIdentifier(string $baseDir, int $revision): string
    {
        return rtrim($baseDir, '/') . $this->packagePath . '/@' . $revision;
    }
}
