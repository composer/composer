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

use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgDriver extends VcsDriver
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
        if (Filesystem::isLocalPath($this->url)) {
            $this->repoDir = $this->url;
        } else {
            $cacheDir = $this->config->get('cache-vcs-dir');
            $this->repoDir = $cacheDir . '/' . preg_replace('{[^a-z0-9]}i', '-', $this->url) . '/';

            $fs = new Filesystem();
            $fs->ensureDirectoryExists($cacheDir);

            if (!is_writable(dirname($this->repoDir))) {
                throw new \RuntimeException('Can not clone '.$this->url.' to access package information. The "'.$cacheDir.'" directory is not writable by the current user.');
            }

            if (preg_match('{^http:}i', $this->url) && $this->config->get('secure-http')) {
                throw new TransportException("Your configuration does not allow connection to $url. See https://getcomposer.org/doc/06-config.md#secure-http for details.");
            }

            // update the repo if it is a valid hg repository
            if (is_dir($this->repoDir) && 0 === $this->process->execute('hg summary', $output, $this->repoDir)) {
                if (0 !== $this->process->execute('hg pull', $output, $this->repoDir)) {
                    $this->io->writeError('<error>Failed to update '.$this->url.', package information from this repository may be outdated ('.$this->process->getErrorOutput().')</error>');
                }
            } else {
                // clean up directory and do a fresh clone into it
                $fs->removeDirectory($this->repoDir);

                if (0 !== $this->process->execute(sprintf('hg clone --noupdate %s %s', ProcessExecutor::escape($this->url), ProcessExecutor::escape($this->repoDir)), $output, $cacheDir)) {
                    $output = $this->process->getErrorOutput();

                    if (0 !== $this->process->execute('hg --version', $ignoredOutput)) {
                        throw new \RuntimeException('Failed to clone '.$this->url.', hg was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
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
            $this->process->execute(sprintf('hg tip --template "{node}"'), $output, $this->repoDir);
            $output = $this->process->splitLines($output);
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
        return array('type' => 'hg', 'url' => $this->getUrl(), 'reference' => $identifier);
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
            $this->process->execute(sprintf('hg cat -r %s composer.json', ProcessExecutor::escape($identifier)), $composer, $this->repoDir);

            if (!trim($composer)) {
                return;
            }

            $composer = JsonFile::parseJson($composer, $identifier);

            if (empty($composer['time'])) {
                $this->process->execute(sprintf('hg log --template "{date|rfc3339date}" -r %s', ProcessExecutor::escape($identifier)), $output, $this->repoDir);
                $date = new \DateTime(trim($output), new \DateTimeZone('UTC'));
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
            $tags = array();

            $this->process->execute('hg tags', $output, $this->repoDir);
            foreach ($this->process->splitLines($output) as $tag) {
                if ($tag && preg_match('(^([^\s]+)\s+\d+:(.*)$)', $tag, $match)) {
                    $tags[$match[1]] = $match[2];
                }
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
            $bookmarks = array();

            $this->process->execute('hg branches', $output, $this->repoDir);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && preg_match('(^([^\s]+)\s+\d+:([a-f0-9]+))', $branch, $match)) {
                    $branches[$match[1]] = $match[2];
                }
            }

            $this->process->execute('hg bookmarks', $output, $this->repoDir);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && preg_match('(^(?:[\s*]*)([^\s]+)\s+\d+:(.*)$)', $branch, $match)) {
                    $bookmarks[$match[1]] = $match[2];
                }
            }

            // Branches will have preference over bookmarks
            $this->branches = array_merge($bookmarks, $branches);
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (preg_match('#(^(?:https?|ssh)://(?:[^@]@)?bitbucket.org|https://(?:.*?)\.kilnhg.com)#i', $url)) {
            return true;
        }

        // local filesystem
        if (Filesystem::isLocalPath($url)) {
            $url = Filesystem::getPlatformPath($url);
            if (!is_dir($url)) {
                return false;
            }

            $process = new ProcessExecutor();
            // check whether there is a hg repo in that path
            if ($process->execute('hg summary', $output, $url) === 0) {
                return true;
            }
        }

        if (!$deep) {
            return false;
        }

        $processExecutor = new ProcessExecutor();
        $exit = $processExecutor->execute(sprintf('hg identify %s', ProcessExecutor::escape($url)), $ignored);

        return $exit === 0;
    }
}
