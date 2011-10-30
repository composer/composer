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
            'type',
            'names',
            'extra',
            'installationSource' => 'installation-source',
            'version',
            'license',
            'requires',
            'conflicts',
            'provides',
            'replaces',
            'recommends',
            'suggests',
            'autoload',
            'repositories',
        );

        $data = array();
        $data['name'] = $package->getPrettyName();
        if ($package->getTargetDir()) {
            $data['target-dir'] = $package->getTargetDir();
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
