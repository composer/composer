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

            $fs = new Filesystem();
            $fs->ensureDirectoryExists(dirname($this->repoDir));

            if (!is_writable(dirname($this->repoDir))) {
                throw new \RuntimeException('Can not clone '.$this->url.' to access package information. The "'.dirname($this->repoDir).'" directory is not writable by the current user.');
            }

            // update the repo if it is a valid git repository
            if (is_dir($this->repoDir) && 0 === $this->process->execute('git remote', $output, $this->repoDir)) {
                if (0 !== $this->process->execute('git remote update --prune origin', $output, $this->repoDir)) {
                    $this->io->write('<error>Failed to update '.$this->url.', package information from this repository may be outdated ('.$this->process->getErrorOutput().')</error>');
                }
            } else {
                // clean up directory and do a fresh clone into it
                $fs->removeDirectory($this->repoDir);

                // added in git 1.7.1, prevents prompting the user
                putenv('GIT_ASKPASS=echo');
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
            $resource = sprintf('%s:composer.json', escapeshellarg($identifier));
            $this->process->execute(sprintf('git show %s', $resource), $composer, $this->repoDir);

            if (!trim($composer)) {
                return;
            }

            $composer = JsonFile::parseJson($composer, $resource);

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

            $this->process->execute('git branch --no-color --no-abbrev -v', $output, $this->repoDir);
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch && !preg_match('{^ *[^/]+/HEAD }', $branch)) {
                    if (preg_match('{^(?:\* )? *(\S+) *([a-f0-9]+) .*$}', $branch, $match)) {
                       $branches[$match[1]] = $match[2];
                    }
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
        if (preg_match('#(^git://|\.git$|git(?:olite)?@|//git\.|//github.com/)#i', $url)) {
            return true;
        }

        // local filesystem
        if (static::isLocalUrl($url)) {
            if (!is_dir($url)) {
                throw new \RuntimeException('Directory does not exist: '.$url);
            }

            $process = new ProcessExecutor();
            $url = str_replace('file://', '', $url);
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
