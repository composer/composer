<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class SvnDriver extends VcsDriver implements VcsDriverInterface
{
    protected $baseUrl;
    protected $tags;
    protected $branches;
    protected $infoCache = array();

    public function __construct($url, InputInterface $input, OutputInterface $output)
    {
        parent::__construct($this->baseUrl = rtrim($url, '/'), $input, $output);

        if (false !== ($pos = strrpos($url, '/trunk'))) {
            $this->baseUrl = substr($url, 0, $pos);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->getBranches();
        $this->getTags();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return 'trunk';
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
        return array('type' => 'svn', 'url' => $this->baseUrl, 'reference' => $identifier);
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
            preg_match('{^(.+?)(@\d+)?$}', $identifier, $match);
            if (!empty($match[2])) {
                $identifier = $match[1];
                $rev = $match[2];
            } else {
                $rev = '';
            }

            exec(sprintf('svn cat --non-interactive %s', escapeshellarg($this->baseUrl.$identifier.'composer.json'.$rev)), $output);
            $composer = implode("\n", $output);
            unset($output);

            if (!$composer) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                exec(sprintf('svn info %s', escapeshellarg($this->baseUrl.$identifier.$rev)), $output);
                foreach ($output as $line) {
                    if (preg_match('{^Last Changed Date: ([^(]+)}', $line, $match)) {
                        $date = new \DateTime($match[1]);
                        $composer['time'] = $date->format('Y-m-d H:i:s');
                        break;
                    }
                }
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
            exec(sprintf('svn ls --non-interactive %s', escapeshellarg($this->baseUrl.'/tags')), $output);
            $this->tags = array();
            foreach ($output as $tag) {
                $this->tags[rtrim($tag, '/')] = '/tags/'.$tag;
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            exec(sprintf('svn ls --verbose --non-interactive %s', escapeshellarg($this->baseUrl.'/')), $output);

            $this->branches = array();
            foreach ($output as $line) {
                preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match);
                if ($match[2] === 'trunk/') {
                    $this->branches['trunk'] = '/trunk/@'.$match[1];
                    break;
                }
            }
            unset($output);

            exec(sprintf('svn ls --verbose --non-interactive %s', escapeshellarg($this->baseUrl.'/branches')), $output);
            foreach ($output as $line) {
                preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match);
                if ($match[2] === './') {
                    continue;
                }
                $this->branches[rtrim($match[2], '/')] = '/branches/'.$match[2].'@'.$match[1];
            }
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
        if (preg_match('#(^svn://|//svn\.)#i', $url)) {
            return true;
        }

        if (!$deep) {
            return false;
        }

        exec(sprintf('svn info --non-interactive %s 2>/dev/null', escapeshellarg($url)), $ignored, $exit);
        return $exit === 0;
    }
}
