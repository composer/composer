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
 */
class ArrayDumper
{
    public function dump(PackageInterface $package)
    {
        $keys = array(
            'type',
            'names',
            'extra',
            'installationSource',
            'sourceType',
            'sourceUrl',
            'distType',
            'distUrl',
            'distSha1Checksum',
            'version',
            'license',
            'requires',
            'conflicts',
            'provides',
            'replaces',
            'recommends',
            'suggests'
        );

        $data = array();
        $data['name'] = $package->getPrettyName();
        foreach ($keys as $key) {
            $getter = 'get'.ucfirst($key);
            $value  = $package->$getter();

            if (null !== $value && !(is_array($value) && 0 === count($value))) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
