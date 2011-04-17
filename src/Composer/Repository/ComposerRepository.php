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
            throw new \UnexpectedValueException('Could not parse package list from the '.$this->url.' registry');
        }

        foreach ($packages as $data) {
            $this->createPackages($data);
        }
    }

    protected function createPackages($data)
    {
        foreach ($data['versions'] as $rev) {
            $version = $this->parseVersion($rev['version']);

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

    protected function parseVersion($version)
    {
        if (!preg_match('#^v?(\d+)(\.\d+)?(\.\d+)?-?(beta|RC\d+|alpha|dev)?$#i', $version, $matches)) {
            throw new \UnexpectedValueException('Invalid version string '.$version);
        }

        return array(
            'version' => $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0'),
            'type' => strtolower(!empty($matches[4]) ? $matches[4] : 'stable'),
        );
    }
}