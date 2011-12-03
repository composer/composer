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

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Json\JsonFile;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository
{
    protected $url;
    protected $packages;

    public function __construct(array $config)
    {
        if (!preg_match('{^https?://}', $config['url'])) {
            $config['url'] = 'http://'.$config['url'];
        }
        $config['url'] = rtrim($config['url'], '/');
        if (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for Composer repository: '.$config['url']);
        }

        $this->url = $config['url'];
    }

    protected function initialize()
    {
        parent::initialize();
        $json     = new JsonFile($this->url.'/packages.json');
        $packages = $json->read();
        if (!$packages) {
            throw new \UnexpectedValueException('Could not parse package list from the '.$this->url.' repository');
        }

        $loader = new ArrayLoader($this->repositoryManager);
        foreach ($packages as $data) {
            foreach ($data['versions'] as $rev) {
                $this->addPackage($loader->load($rev));
            }
        }
    }
}
