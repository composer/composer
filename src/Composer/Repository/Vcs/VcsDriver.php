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
use Composer\Downloader\TransportException;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;
use Composer\Util\HttpDownloader;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;

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
    /** @var array<string, mixed> */
    protected $repoConfig;
    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var ProcessExecutor */
    protected $process;
    /** @var HttpDownloader */
    protected $httpDownloader;
    /** @var array<int|string, mixed> */
    protected $infoCache = [];
    /** @var ?Cache */
    protected $cache;

    /**
     * Constructor.
     *
     * @param array{url: string}&array<string, mixed>           $repoConfig     The repository configuration
     * @param IOInterface     $io             The IO instance
     * @param Config          $config         The composer configuration
     * @param HttpDownloader  $httpDownloader Remote Filesystem, injectable for mocking
     * @param ProcessExecutor $process        Process instance, injectable for mocking
     */
    final public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, ProcessExecutor $process)
    {
        if (Filesystem::isLocalPath($repoConfig['url'])) {
            $repoConfig['url'] = Filesystem::getPlatformPath($repoConfig['url']);
        }

        $this->url = $repoConfig['url'];
        $this->originUrl = $repoConfig['url'];
        $this->repoConfig = $repoConfig;
        $this->io = $io;
        $this->config = $config;
        $this->httpDownloader = $httpDownloader;
        $this->process = $process;
    }

    /**
     * Returns whether or not the given $identifier should be cached or not.
     * @phpstan-assert-if-true !null $this->cache
     */
    protected function shouldCache(string $identifier): bool
    {
        return $this->cache && Preg::isMatch('{^[a-f0-9]{40}$}iD', $identifier);
    }

    /**
     * @inheritDoc
     */
    public function getComposerInformation(string $identifier): ?array
    {
        if (!isset($this->infoCache[$identifier])) {
            if ($this->shouldCache($identifier) && $res = $this->cache->read($identifier)) {
                return $this->infoCache[$identifier] = JsonFile::parseJson($res);
            }

            $composer = $this->getBaseComposerInformation($identifier);

            if ($this->shouldCache($identifier)) {
                $this->cache->write($identifier, JsonFile::encode($composer, 0));
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * @return array<mixed>|null
     */
    protected function getBaseComposerInformation(string $identifier): ?array
    {
        $composerFileContent = $this->getFileContent('composer.json', $identifier);

        if (!$composerFileContent) {
            return null;
        }

        $composer = JsonFile::parseJson($composerFileContent, $identifier . ':composer.json');

        if ([] === $composer || !is_array($composer)) {
            return null;
        }

        if (empty($composer['time']) && null !== ($changeDate = $this->getChangeDate($identifier))) {
            $composer['time'] = $changeDate->format(DATE_RFC3339);
        }

        return $composer;
    }

    /**
     * @inheritDoc
     */
    public function hasComposerFile(string $identifier): bool
    {
        try {
            return null !== $this->getComposerInformation($identifier);
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
    protected function getScheme(): string
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
     * @throws TransportException
     */
    protected function getContents(string $url): Response
    {
        $options = $this->repoConfig['options'] ?? [];

        return $this->httpDownloader->get($url, $options);
    }

    /**
     * @inheritDoc
     */
    public function cleanup(): void
    {
    }
}
