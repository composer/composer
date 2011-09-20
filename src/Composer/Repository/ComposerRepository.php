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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository
{
    protected $url;
    protected $packages;

    public function __construct($url)
    {
        $url = rtrim($url, '/');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for Composer repository: '.$url);
        }

        $this->url = $url;
    }

    protected function initialize()
    {
        parent::initialize();
        $packages = @json_decode(file_get_contents($this->url.'/packages.json'), true);
        if (!$packages) {
            throw new \UnexpectedValueException('Could not parse package list from the '.$this->url.' repository');
        }

        foreach ($packages as $data) {
            $this->createPackages($data);
        }
    }

    private function createPackages($data)
    {
        foreach ($data['versions'] as $rev) {
            $loader = new ArrayLoader();
            $this->addPackage($loader->load($rev));
        }
    }
}
