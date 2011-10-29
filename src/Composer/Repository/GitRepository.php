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

namespace Composer\Repository;

use Composer\Package\MemoryPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Loader\ArrayLoader;
use Composer\Json\JsonFile;

/**
 * FIXME This is majorly broken and incomplete, it was an experiment
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitRepository extends ArrayRepository
{
    protected $url;

    public function __construct(array $url)
    {
        if (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for PEAR repository: '.$url);
        }

        $this->url = $url;
    }

    protected function initialize()
    {
        parent::initialize();

        if (preg_match('#^(?:https?|git(?:\+ssh)?|ssh)://#', $this->url)) {
            // check if the repo is on github.com, read the composer.json file & branch/tags out of it
            // otherwise, maybe we have to clone the repo to figure out what's in it
            throw new \Exception('Not implemented yet');
        } elseif (file_exists($this->url)) {
            if (!file_exists($this->url.'/composer.json')) {
                throw new \InvalidArgumentException('The repository at url '.$this->url.' does not contain a composer.json file.');
            }
            $json   = new JsonFile($this->url.'/composer.json');
            $config = $json->read();
            if (!$config) {
                throw new \UnexpectedValueException('Config file could not be parsed: '.$this->url.'/composer.json. Probably a JSON syntax error.');
            }
        } else {
            throw new \InvalidArgumentException('Could not find repository at url '.$this->url);
        }

        $loader = new ArrayLoader($this->repositoryManager);
        $this->addPackage($loader->load($config));
    }
}
