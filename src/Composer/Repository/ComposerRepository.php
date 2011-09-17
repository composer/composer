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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ComposerRepository extends ArrayRepository
{
    protected $packages;

    static public function supports($type, $name = '', $url = '')
    {
        return 'composer' === strtolower($type) && '' !== $url;
    }

    static public function create($type, $name = '', $url = '')
    {
        return new static($url);
    }

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

            $package->setDistType($rev['dist']['type']);
            $package->setDistUrl($rev['dist']['url']);
            $package->setDistSha1Checksum($rev['dist']['shasum']);

            if (isset($rev['type'])) {
                $package->setType($rev['type']);
            }

            if (isset($rev['extra'])) {
                $package->setExtra($rev['extra']);
            }

            if (isset($rev['license'])) {
                $package->setLicense($rev['license']);
            }

            $links = array(
                'require',
                'conflict',
                'provide',
                'replace',
                'recommend',
                'suggest',
            );
            foreach ($links as $link) {
                if (isset($rev[$link])) {
                    $method = 'set'.$link.'s';
                    $package->{$method}($this->createLinks($rev['name'], $link.'s', $rev[$link]));
                }
            }
            $this->addPackage($package);
        }
    }

    protected function createLinks($name, $description, $linkSpecs)
    {
        $links = array();
        foreach ($linkSpecs as $dep => $ver) {
            preg_match('#^([>=<~]*)([\d.]+.*)$#', $ver, $match);
            if (!$match[1]) {
                $match[1] = '=';
            }
            $constraint = new VersionConstraint($match[1], $match[2]);
            $links[] = new Link($name, $dep, $constraint, $description);
        }
        return $links;
    }
}
