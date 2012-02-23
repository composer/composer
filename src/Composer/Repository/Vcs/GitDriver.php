<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDriver extends VcsDriver implements VcsDriverInterface
{
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $repoDir;
    protected $infoCache = array();
    protected $isLocal = false;

    public function __construct($url, IOInterface $io, ProcessExecutor $process = null)
    {
        parent::__construct($url, $io, $process);
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $url = escapeshellarg($this->url);

        if (static::isLocalUrl($this->url)) {
            $this->isLocal = true;
            $this->repoDir = $this->url;
        } else {
            $this->repoDir = sys_get_temp_dir() . '/composer-' . preg_replace('{[^a-z0-9]}i', '-', $url) . '/';
            $repoDir = escapeshellarg($this->repoDir);
            if (is_dir($this->repoDir)) {
                $this->process->execute(sprintf('cd %s && git fetch origin', $repoDir), $output);
            } else {
                $this->process->execute(sprintf('git clone %s %s', $url, $repoDir), $output);
            }
        }

        $this->getTags();
        $this->getBranches();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if (null === $this->rootIdentifier) {
            $this->rootIdentifier = 'master';

            if ($this->isLocal) {
                // select currently checked out branch if master is not available
                $this->process->execute(sprintf('cd %s && git branch --no-color', escapeshellarg($this->repoDir)), $output);
                $branches = $this->process->splitLines($output);
                if (!in_array('* master', $branches)) {
                    foreach ($branches as $branch) {
                        if ($branch && preg_match('{^\* +(\S+)}', $branch, $match)) {
                            $this->rootIdentifier = $match[1];
                            break;
                        }
                    }
                }
            } else {
                // try to find a non-master remote HEAD branch
                $this->process->execute(sprintf('cd %s && git branch --no-color -r', escapeshellarg($this->repoDir)), $output);
                foreach ($this->process->splitLines($output) as $branch) {
                    if ($branch && preg_match('{/HEAD +-> +[^/]+/(\S+)}', $branch, $match)) {
                        $this->rootIdentifier = $match[1];
                        break;
                    }
                }
            }
        }

        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        $label = array_search($identifier, (array) $this->tags) ?: $identifier;

        return array('type' => 'git', 'url' => $this->getUrl(), 'reference' => $label);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            $this->process->execute(sprintf('cd %s && git show %s:composer.json', escapeshellarg($this->repoDir), escapeshellarg($identifier)), $composer);

            if (!trim($composer)) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $this->process->execute(sprintf('cd %s && git log -1 --format=%%at %s', escapeshellarg($this->repoDir), escapeshellarg($identifier)), $output);
                $date = new \DateTime('@'.trim($output));
                $composer['time'] = $date->format('Y-m-d H:i:s');
            }
            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if (null === $this->tags) {
            $this->process->execute(sprintf('cd %s && git tag', escapeshellarg($this->repoDir)), $output);
            $output = $this->process->splitLines($output);
            $this->tags = $output ? array_combine($output, $output) : array();
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $branches = array();

            $this->process->execute(sprintf(
                'cd %s && git branch --no-color --no-abbrev -v %s',
                escapeshellarg($this->repoDir),
                $this->isLocal ? '' : '-r'
            ), $output);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && !preg_match('{^ *[^/]+/HEAD }', $branch)) {
                    preg_match('{^(?:\* )? *(?:[^/]+/)?(\S+) *([a-f0-9]+) .*$}', $branch, $match);
                    $branches[$match[1]] = $match[2];
                }
            }

            $this->branches = $branches;
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        try {
            $this->getComposerInformation($identifier);
            return true;
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports($url, $deep = false)
    {
        if (preg_match('#(^git://|\.git$|git@|//git\.)#i', $url)) {
            return true;
        }

        // local filesystem
        if (static::isLocalUrl($url)) {
            $process = new ProcessExecutor();
            // check whether there is a git repo in that path
            if ($process->execute(sprintf('cd %s && git show', escapeshellarg($url)), $output) === 0) {
                return true;
            }
        }

        if (!$deep) {
            return false;
        }

        // TODO try to connect to the server
        return false;
    }
}
