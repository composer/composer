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

use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDriver extends VcsDriver
{
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $repoDir;
    protected $infoCache = array();

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        if (static::isLocalUrl($this->url)) {
            $this->repoDir = str_replace('file://', '', $this->url);
        } else {
            $this->repoDir = $this->config->get('home') . '/cache.git/' . preg_replace('{[^a-z0-9.]}i', '-', $this->url) . '/';

            // update the repo if it is a valid git repository
            if (is_dir($this->repoDir) && 0 === $this->process->execute('git remote', $output, $this->repoDir)) {
                if (0 !== $this->process->execute('git remote update --prune origin', $output, $this->repoDir)) {
                    $this->io->write('<error>Failed to update '.$this->url.', package information from this repository may be outdated ('.$this->process->getErrorOutput().')</error>');
                }
            } else {
                // clean up directory and do a fresh clone into it
                $fs = new Filesystem();
                $fs->removeDirectory($this->repoDir);

                $command = sprintf('git clone --mirror %s %s', escapeshellarg($this->url), escapeshellarg($this->repoDir));
                if (0 !== $this->process->execute($command, $output)) {
                    $output = $this->process->getErrorOutput();

                    if (0 !== $this->process->execute('git --version', $ignoredOutput)) {
                        throw new \RuntimeException('Failed to clone '.$this->url.', git was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
                    }

                    throw new \RuntimeException('Failed to clone '.$this->url.', could not read packages from it' . "\n\n" .$output);
                }
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

            // select currently checked out branch if master is not available
            $this->process->execute('git branch --no-color', $output, $this->repoDir);
            $branches = $this->process->splitLines($output);
            if (!in_array('* master', $branches)) {
                foreach ($branches as $branch) {
                    if ($branch && preg_match('{^\* +(\S+)}', $branch, $match)) {
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
            $this->process->execute(sprintf('git show %s:composer.json', escapeshellarg($identifier)), $composer, $this->repoDir);

            if (!trim($composer)) {
                return;
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $this->process->execute(sprintf('git log -1 --format=%%at %s', escapeshellarg($identifier)), $output, $this->repoDir);
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
            $this->process->execute('git tag', $output, $this->repoDir);
            $output = $this->process->splitLines($output);
            $this->tags = $output ? array_combine($output, $output) : array();

            $this->filterTags();
        }

        return $this->tags;
    }

    /**
     * Filter out invalid tags
     *
     * This is done by searching the tag's commit for a
     * git note with a `refs/notes/composer-invalidate/<version>`
     * name. If found, the version is considered invalid and is
     * discarded.
     *
     * To invalidate a version, e.g. 1.0.0, assuming it is
     * using the `v1.0.0` tag, here is how you would do it:
     *
     *     git notes --ref=composer-invalidate/v1.0.0 add -m 'foo' -f v1.0.0
     *     git push -f origin refs/notes/composer-invalidate/*
     *
     * To check if a tag has been invalidated, you can use:
     *
     *     git fetch origin refs/notes/composer-invalidate/*:refs/notes/composer-invalidate/*
     *     git notes --ref=composer-invalidate/v1.0.0 show v1.0.0
     *
     * To remove the note and make the version valid again,
     * you can do:
     *
     *     git fetch origin refs/notes/composer-invalidate:refs/notes/composer-invalidate
     *     git notes --ref=composer-invalidate/v1.0.0 remove v1.0.0
     *     git push -f origin refs/notes/composer-invalidate/*
     */
    protected function filterTags()
    {
        $this->process->execute(sprintf('cd %s && git fetch -f origin refs/notes/composer-invalidate/*:refs/notes/composer-invalidate/*', escapeshellarg($this->repoDir)));
        $invalidTags = array();
        foreach ($this->tags as $tag) {
            $this->process->execute(sprintf(
                'cd %s && git notes --ref=%s show %s',
                escapeshellarg($this->repoDir),
                escapeshellarg("composer-invalidate/$tag"),
                escapeshellarg($tag)
            ), $output);
            $output = $this->process->splitLines(trim($output));
            if ($output) {
                $invalidTags[$tag] = $tag;
            }
        }

        foreach ($this->tags as $tag) {
            if (isset($invalidTags[$tag])) {
                unset($this->tags[$tag]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $branches = array();

            $this->process->execute('git branch --no-color --no-abbrev -v', $output, $this->repoDir);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && !preg_match('{^ *[^/]+/HEAD }', $branch)) {
                    preg_match('{^(?:\* )? *(?:[^/ ]+?/)?(\S+) *([a-f0-9]+) .*$}', $branch, $match);
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
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        if (preg_match('#(^git://|\.git$|git@|//git\.|//github.com/)#i', $url)) {
            return true;
        }

        // local filesystem
        if (static::isLocalUrl($url)) {
            $process = new ProcessExecutor();
            // check whether there is a git repo in that path
            if ($process->execute('git tag', $output, $url) === 0) {
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
