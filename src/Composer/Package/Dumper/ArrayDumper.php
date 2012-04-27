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

namespace Composer\Package\Dumper;

use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ArrayDumper
{
    public function dump(PackageInterface $package)
    {
        $keys = array(
            'binaries' => 'bin',
            'scripts',
            'type',
            'extra',
            'installationSource' => 'installation-source',
            'license',
            'authors',
            'description',
            'homepage',
            'keywords',
            'autoload',
            'repositories',
            'includePaths' => 'include-path',
        );

        $data = array();
        $data['name'] = $package->getPrettyName();
        $data['version'] = $package->getPrettyVersion();
        $data['version_normalized'] = $package->getVersion();

        if ($package->getTargetDir()) {
            $data['target-dir'] = $package->getTargetDir();
        }

        if ($package->getReleaseDate()) {
            $data['time'] = $package->getReleaseDate()->format('Y-m-d H:i:s');
        }

        if ($package->getSourceType()) {
            $data['source']['type'] = $package->getSourceType();
            $data['source']['url'] = $package->getSourceUrl();
            $data['source']['reference'] = $package->getSourceReference();
        }

        if ($package->getDistType()) {
            $data['dist']['type'] = $package->getDistType();
            $data['dist']['url'] = $package->getDistUrl();
            $data['dist']['reference'] = $package->getDistReference();
            $data['dist']['shasum'] = $package->getDistSha1Checksum();
        }

        foreach (BasePackage::$supportedLinkTypes as $type => $opts) {
            if ($links = $package->{'get'.ucfirst($opts['method'])}()) {
                foreach ($links as $link) {
                    $data[$type][$link->getTarget()] = $link->getPrettyConstraint();
                }
            }
        }

        if ($packages = $package->getSuggests()) {
            $data['suggest'] = $packages;
        }

        foreach ($keys as $method => $key) {
            if (is_numeric($method)) {
                $method = $key;
            }

            $getter = 'get'.ucfirst($method);
            $value  = $package->$getter();

            if (null !== $value && !(is_array($value) && 0 === count($value))) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
