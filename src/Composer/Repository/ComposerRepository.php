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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class ComposerRepository extends ArrayRepository
{
    protected $url;
    protected $packages;
    protected $input;
    protected $output;

    public function __construct(InputInterface $input, OutputInterface $output, array $config)
    {
        $this->input = $input;
        $this->output = $output;

        if (!preg_match('{^\w+://}', $config['url'])) {
            // assume http as the default protocol
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

        $loader = new ArrayLoader();
        foreach ($packages as $data) {
            foreach ($data['versions'] as $rev) {
                $this->addPackage($loader->load($rev));
            }
        }
    }
}
