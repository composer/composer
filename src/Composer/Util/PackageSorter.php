<?php


namespace Composer\Util;

use Composer\Package\Link;
use Composer\Package\PackageInterface;

class PackageSorter
{
    /**
     * Sorts packages by dependency weight
     *
     * Packages of equal weight retain the original order
     *
     * @param  array $packages
     * @return array
     */
    public static function sortPackages(array $packages) {
        $usageList = array();

        foreach ($packages as $package) { /** @var PackageInterface $package */
            foreach (array_merge($package->getRequires(), $package->getDevRequires()) as $link) { /** @var Link $link */
                $target = $link->getTarget();
                $usageList[$target][] = $package->getName();
            }
        }
        $computing = array();
        $computed = array();
        $computeImportance = function ($name) use (&$computeImportance, &$computing, &$computed, $usageList) {
            // reusing computed importance
            if (isset($computed[$name])) {
                return $computed[$name];
            }

            // canceling circular dependency
            if (isset($computing[$name])) {
                return 0;
            }

            $computing[$name] = true;
            $weight = 0;

            if (isset($usageList[$name])) {
                foreach ($usageList[$name] as $user) {
                    $weight -= 1 - $computeImportance($user);
                }
            }

            unset($computing[$name]);
            $computed[$name] = $weight;

            return $weight;
        };

        $weightList = array();

        foreach ($packages as $name => $package) {
            $weight = $computeImportance($name);
            $weightList[$name] = $weight;
        }

        $stable_sort = function (&$array) {
            static $transform, $restore;

            $i = 0;

            if (!$transform) {
                $transform = function (&$v, $k) use (&$i) {
                    $v = array($v, ++$i, $k, $v);
                };

                $restore = function (&$v) {
                    $v = $v[3];
                };
            }

            array_walk($array, $transform);
            asort($array);
            array_walk($array, $restore);
        };

        $stable_sort($weightList);

        $sortedPackages = array();

        foreach (array_keys($weightList) as $name) {
            $sortedPackages[] = $packages[$name];
        }
        return $sortedPackages;
    }
}
