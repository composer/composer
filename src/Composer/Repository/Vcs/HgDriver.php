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

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgDriver implements VcsDriverInterface
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
            exec(sprintf('cd %s && hg pull -u', $tmpDir), $output);
        } else {
            exec(sprintf('hg clone %s %s', $url, $tmpDir), $output);
        }

        $this->getTags();
        $this->getBranches();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        $tmpDir = escapeshellarg($this->tmpDir);
        if (null === $this->rootIdentifier) {
            exec(sprintf('cd %s && hg tip --template "{rev}:{node|short}" --color never', $tmpDir), $output);
            $this->rootIdentifier = $output[0];
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
        $label = array_search($identifier, (array)$this->tags) ? : $identifier;

        return array('type' => 'hg', 'url' => $this->getUrl(), 'reference' => $label);
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
            exec(sprintf('cd %s && hg cat --color never -r %s composer.json', escapeshellarg($this->tmpDir), escapeshellarg($identifier)), $output);
            $composer = implode("\n", $output);
            unset($output);

            if (!$composer) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier ' . $identifier . ' in ' . $this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                exec(sprintf('cd %s && hg log --template "{date|rfc822date}" -r %s', escapeshellarg($this->tmpDir), escapeshellarg($identifier)), $output);
                $date = new \DateTime($output[0]);
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
            exec(sprintf('cd %s && hg tags --color never', escapeshellarg($this->tmpDir)), $output);
            foreach ($output as $tag) {
                preg_match('(^([^\s]+)[\s]+[\d+]:(.*)$)', $tag, $match);
                $tags[$match[1]] = $match[2];
            }
            unset($tags['tip']);
            $this->tags = $tags;
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

            exec(sprintf('cd %s && hg branches --color never', escapeshellarg($this->tmpDir)), $output);
            foreach ($output as $branch) {
                preg_match('(^([^\s]+)[\s]+[\d+]:(.*)$)', $branch, $match);
                $branches[$match[1]] = $match[2];
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
        if (preg_match('#(^(?:https?|ssh)://(?:[^@]@)?bitbucket.org|https://(?:.*?)\.kilnhg.com)#i', $url)) {
            return true;
        }

        if (!$deep) {
            return false;
        }

        exec(sprintf('hg identify %s', escapeshellarg($url)), $output);

        return (boolean)$output;
    }
}
