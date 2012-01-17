<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\Util\Process;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDriver implements VcsDriverInterface
{
    protected $url;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $infoCache = array();

    public function __construct($url)
    {
        $this->url = $url;
        $this->tmpDir = sys_get_temp_dir() . '/composer-' . preg_replace('{[^a-z0-9]}i', '-', $url) . '/';
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $url = escapeshellarg($this->url);
        $tmpDir = escapeshellarg($this->tmpDir);
        if (is_dir($this->tmpDir)) {
            Process::execute(sprintf('cd %s && git fetch origin', $tmpDir), $output);
        } else {
            Process::execute(sprintf('git clone %s %s', $url, $tmpDir), $output);
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
            Process::execute(sprintf('cd %s && git branch --no-color -r', escapeshellarg($this->tmpDir)), $output);
            foreach ($output as $branch) {
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
            Process::execute(sprintf('cd %s && git show %s:composer.json', escapeshellarg($this->tmpDir), escapeshellarg($identifier)), $output);
            $composer = implode("\n", $output);
            unset($output);

            if (!$composer) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                Process::execute(sprintf('cd %s && git log -1 --format=%%at %s', escapeshellarg($this->tmpDir), escapeshellarg($identifier)), $output);
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
            Process::execute(sprintf('cd %s && git tag', escapeshellarg($this->tmpDir)), $output);
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

            Process::execute(sprintf('cd %s && git branch --no-color -rv', escapeshellarg($this->tmpDir)), $output);
            foreach ($output as $branch) {
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
