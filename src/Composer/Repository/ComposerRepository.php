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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository
{
    protected $packages;

    public function __construct($url)
    {
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

    protected function createPackages($data)
    {
        foreach ($data['versions'] as $rev) {
            $version = BasePackage::parseVersion($rev['version']);

            $package = new MemoryPackage($rev['name'], $version['version'], $version['type']);
            $package->setSourceType($rev['source']['type']);
            $package->setSourceUrl($rev['source']['url']);

            if (isset($rev['license'])) {
                $package->setLicense($rev['license']);
            }
            //if (isset($rev['require'])) {
            //    $package->setRequires($rev['require']);
            //}
            //if (isset($rev['conflict'])) {
            //    $package->setConflicts($rev['conflict']);
            //}
            //if (isset($rev['provide'])) {
            //    $package->setProvides($rev['provide']);
            //}
            //if (isset($rev['replace'])) {
            //    $package->setReplaces($rev['replace']);
            //}
            //if (isset($rev['recommend'])) {
            //    $package->setRecommends($rev['recommend']);
            //}
            //if (isset($rev['suggest'])) {
            //    $package->setSuggests($rev['suggest']);
            //}
            $this->addPackage($package);
        }
    }
}