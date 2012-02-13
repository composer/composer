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
    protected $infoCache = array();

    public function __construct($url, IOInterface $io, ProcessExecutor $process = null)
    {
        $this->tmpDir = sys_get_temp_dir() . '/composer-' . preg_replace('{[^a-z0-9]}i', '-', $url) . '/';

        parent::__construct($url, $io, $process);
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $url = escapeshellarg($this->url);
        $tmpDir = escapeshellarg($this->tmpDir);
        if (is_dir($this->tmpDir)) {
            $this->process->execute(sprintf('cd %s && git fetch origin', $tmpDir), $output);
        } else {
            $this->process->execute(sprintf('git clone %s %s', $url, $tmpDir), $output);
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
            $this->process->execute(sprintf('cd %s && git branch --no-color -r', escapeshellarg($this->tmpDir)), $output);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && preg_match('{/HEAD +-> +[^/]+/(\S+)}', $branch, $match)) {
                    $this->rootIdentifier = $match[1];
                    break;
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
            $this->process->execute(sprintf('cd %s && git show %s:composer.json', escapeshellarg($this->tmpDir), escapeshellarg($identifier)), $composer);

            if (!trim($composer)) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $this->process->execute(sprintf('cd %s && git log -1 --format=%%at %s', escapeshellarg($this->tmpDir), escapeshellarg($identifier)), $output);
                $date = new \DateTime('@'.$output[0]);
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
            $this->process->execute(sprintf('cd %s && git tag', escapeshellarg($this->tmpDir)), $output);
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
     * git note in the `refs/notes/composer` ref. That note
     * is checked for the value `invalid`. If found, the
     * version is considered invalid and is discarded.
     *
     * To invalidate a version, e.g. 1.0.0, assuming it is
     * using the `v1.0.0` tag, here is how you would do it:
     *
     *     git notes --ref=composer add -m 'invalid' -f v1.0.0
     *     git push -f origin refs/notes/composer
     *
     * To check if a tag has been invalidated, you can use:
     *
     *     git fetch origin refs/notes/composer:refs/notes/composer
     *     git notes --ref=composer show v1.0.0
     *
     * To remove the note and make the version valid again,
     * you can do:
     *
     *     git fetch origin refs/notes/composer:refs/notes/composer
     *     git notes --ref=composer remove v1.0.0
     *     git push -f origin refs/notes/composer
     */
    protected function filterTags()
    {
        $this->process->execute(sprintf('cd %s && git fetch -f origin refs/notes/composer:refs/notes/composer', escapeshellarg($this->tmpDir)));
        $invalidTags = array();
        foreach ($this->tags as $tag) {
            $this->process->execute(sprintf(
                'cd %s && git notes --ref=composer show %s',
                escapeshellarg($this->tmpDir),
                escapeshellarg($tag)
            ), $output);
            $output = $this->process->splitLines(trim($output));

            if ($output && in_array('invalid', $output)) {
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

            $this->process->execute(sprintf('cd %s && git branch --no-color -rv', escapeshellarg($this->tmpDir)), $output);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && !preg_match('{^ *[^/]+/HEAD }', $branch)) {
                    preg_match('{^ *[^/]+/(\S+) *([a-f0-9]+) .*$}', $branch, $match);
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

        if (!$deep) {
            return false;
        }

        // TODO try to connect to the server
        return false;
    }
}
