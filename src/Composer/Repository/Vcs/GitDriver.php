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

use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\Url;
use Composer\Util\Git as GitUtil;
use Composer\IO\IOInterface;
use Composer\Cache;
use Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDriver extends VcsDriver
{
    /** @var array<int|string, string> Map of tag name (can be turned to an int by php if it is a numeric name) to identifier */
    protected $tags;
    /** @var array<int|string, string> Map of branch name (can be turned to an int by php if it is a numeric name) to identifier */
    protected $branches;
    /** @var string */
    protected $rootIdentifier;
    /** @var string */
    protected $repoDir;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        if (Filesystem::isLocalPath($this->url)) {
            $this->url = Preg::replace('{[\\/]\.git/?$}', '', $this->url);
            if (!is_dir($this->url)) {
                throw new \RuntimeException('Failed to read package information from '.$this->url.' as the path does not exist');
            }
            $this->repoDir = $this->url;
            $cacheUrl = realpath($this->url);
        } else {
            if (!Cache::isUsable($this->config->get('cache-vcs-dir'))) {
                throw new \RuntimeException('GitDriver requires a usable cache directory, and it looks like you set it to be disabled');
            }

            $this->repoDir = $this->config->get('cache-vcs-dir') . '/' . Preg::replace('{[^a-z0-9.]}i', '-', $this->url) . '/';

            GitUtil::cleanEnv();

            $fs = new Filesystem();
            $fs->ensureDirectoryExists(dirname($this->repoDir));

            if (!is_writable(dirname($this->repoDir))) {
                throw new \RuntimeException('Can not clone '.$this->url.' to access package information. The "'.dirname($this->repoDir).'" directory is not writable by the current user.');
            }

            if (Preg::isMatch('{^ssh://[^@]+@[^:]+:[^0-9]+}', $this->url)) {
                throw new \InvalidArgumentException('The source URL '.$this->url.' is invalid, ssh URLs should have a port number after ":".'."\n".'Use ssh://git@example.com:22/path or just git@example.com:path if you do not want to provide a password or custom port.');
            }

            $gitUtil = new GitUtil($this->io, $this->config, $this->process, $fs);
            if (!$gitUtil->syncMirror($this->url, $this->repoDir)) {
                if (!is_dir($this->repoDir)) {
                    throw new \RuntimeException('Failed to clone '.$this->url.' to read package information from it');
                }
                $this->io->writeError('<error>Failed to update '.$this->url.', package information from this repository may be outdated</error>');
            }

            $cacheUrl = $this->url;
        }

        $this->getTags();
        $this->getBranches();

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.Preg::replace('{[^a-z0-9.]}i', '-', Url::sanitize($cacheUrl)));
        $this->cache->setReadOnly($this->config->get('cache-read-only'));
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier(): string
    {
        if (null === $this->rootIdentifier) {
            $this->rootIdentifier = 'master';

            $gitUtil = new GitUtil($this->io, $this->config, $this->process, new Filesystem());
            $defaultBranch = $gitUtil->getMirrorDefaultBranch($this->url, $this->repoDir, Filesystem::isLocalPath($this->url));
            if ($defaultBranch !== null) {
                return $this->rootIdentifier = $defaultBranch;
            }

            // select currently checked out branch if master is not available
            $this->process->execute('git branch --no-color', $output, $this->repoDir);
            $branches = $this->process->splitLines($output);
            if (!in_array('* master', $branches)) {
                foreach ($branches as $branch) {
                    if ($branch && Preg::isMatch('{^\* +(\S+)}', $branch, $match)) {
                        $this->rootIdentifier = $match[1];
                        break;
                    }
                }
            }
        }

        return $this->rootIdentifier;
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
        return array('type' => 'git', 'url' => $this->getUrl(), 'reference' => $identifier);
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
    public function getFileContent(string $file, string $identifier): ?string
    {
        if (isset($identifier[0]) && $identifier[0] === '-') {
            throw new \RuntimeException('Invalid git identifier detected. Identifier must not start with a -, given: ' . $identifier);
        }

        $resource = sprintf('%s:%s', ProcessExecutor::escape($identifier), ProcessExecutor::escape($file));
        $this->process->execute(sprintf('git show %s', $resource), $content, $this->repoDir);

        if (!trim($content)) {
            return null;
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        $this->process->execute(sprintf(
            'git -c log.showSignature=false log -1 --format=%%at %s',
            ProcessExecutor::escape($identifier)
        ), $output, $this->repoDir);

        return new \DateTimeImmutable('@'.trim($output), new \DateTimeZone('UTC'));
    }

    /**
     * @inheritDoc
     */
    public function getTags(): array
    {
        if (null === $this->tags) {
            $this->tags = array();

            $this->process->execute('git show-ref --tags --dereference', $output, $this->repoDir);
            foreach ($output = $this->process->splitLines($output) as $tag) {
                if ($tag && Preg::isMatch('{^([a-f0-9]{40}) refs/tags/(\S+?)(\^\{\})?$}', $tag, $match)) {
                    $this->tags[$match[2]] = (string) $match[1];
                }
            }
        }

        return $this->tags;
    }

    /**
     * @inheritDoc
     */
    public function getBranches(): array
    {
        if (null === $this->branches) {
            $branches = array();

            $this->process->execute('git branch --no-color --no-abbrev -v', $output, $this->repoDir);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && !Preg::isMatch('{^ *[^/]+/HEAD }', $branch)) {
                    if (Preg::isMatch('{^(?:\* )? *(\S+) *([a-f0-9]+)(?: .*)?$}', $branch, $match) && $match[1][0] !== '-') {
                        $branches[$match[1]] = $match[2];
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
        if (Preg::isMatch('#(^git://|\.git/?$|git(?:olite)?@|//git\.|//github.com/)#i', $url)) {
            return true;
        }

        // local filesystem
        if (Filesystem::isLocalPath($url)) {
            $url = Filesystem::getPlatformPath($url);
            if (!is_dir($url)) {
                return false;
            }

            $process = new ProcessExecutor($io);
            // check whether there is a git repo in that path
            if ($process->execute('git tag', $output, $url) === 0) {
                return true;
            }
        }

        if (!$deep) {
            return false;
        }

        $gitUtil = new GitUtil($io, $config, new ProcessExecutor($io), new Filesystem());
        GitUtil::cleanEnv();

        try {
            $gitUtil->runCommand(function ($url): string {
                return 'git ls-remote --heads -- ' . ProcessExecutor::escape($url);
            }, $url, sys_get_temp_dir());
        } catch (\RuntimeException $e) {
            return false;
        }

        return true;
    }
}
