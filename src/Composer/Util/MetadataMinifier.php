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

namespace Composer\Util;

class MetadataMinifier
{
    public static function expand(array $versions)
    {
        $expanded = array();
        $expandedVersion = null;
        foreach ($versions as $versionData) {
            if (!$expandedVersion) {
                $expandedVersion = $versionData;
                $expanded[] = $expandedVersion;
                continue;
            }

            // add any changes from the previous version to the expanded one
            foreach ($versionData as $key => $val) {
                if ($val === '__unset') {
                    unset($expandedVersion[$key]);
                } else {
                    $expandedVersion[$key] = $val;
                }
            }

            $expanded[] = $expandedVersion;
        }

        return $expanded;
    }

    public static function minify(array $versions)
    {
        $minifiedVersions = array();

        $lastKnownVersionData = null;
        foreach ($versions as $version) {
            if (!$lastKnownVersionData) {
                $lastKnownVersionData = $version;
                $minifiedVersions[] = $version;
                continue;
            }

            $minifiedVersion = array();

            // add any changes from the previous version
            foreach ($version as $key => $val) {
                if (!isset($lastKnownVersionData[$key]) || $lastKnownVersionData[$key] !== $val) {
                    $minifiedVersion[$key] = $val;
                    $lastKnownVersionData[$key] = $val;
                }
            }

            // store any deletions from the previous version for keys missing in current one
            foreach ($lastKnownVersionData as $key => $val) {
                if (!isset($version[$key])) {
                    $minifiedVersion[$key] = "__unset";
                    unset($lastKnownVersionData[$key]);
                }
            }

            $minifiedVersions[] = $minifiedVersion;
        }

        return $minifiedVersions;
    }
}
