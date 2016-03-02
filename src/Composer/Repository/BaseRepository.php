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

use Composer\Package\RootPackageInterface;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Common ancestor class for generic repository functionality.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * Returns a list of links causing the requested needle packages to be installed, as an associative array with the
     * dependent's name as key, and an array containing in order the PackageInterface and Link describing the relationship
     * as values. If recursive lookup was requested a third value is returned containing an identically formed array up
     * to the root package. That third value will be false in case a circular recursion was detected.
     *
     * @param  string|string[]          $needle        The package name(s) to inspect.
     * @param  ConstraintInterface|null $constraint    Optional constraint to filter by.
     * @param  bool                     $invert        Whether to invert matches to discover reasons for the package *NOT* to be installed.
     * @param  bool                     $recurse       Whether to recursively expand the requirement tree up to the root package.
     * @param  string[]                 $packagesFound Used internally when recurring
     * @return array                    An associative array of arrays as described above.
     */
    public function getDependents($needle, $constraint = null, $invert = false, $recurse = true, $packagesFound = null)
    {
        $needles = (array) $needle;
        $results = array();

        // initialize the array with the needles before any recursion occurs
        if (null === $packagesFound) {
            $packagesFound = $needles;
        }

        // Loop over all currently installed packages.
        foreach ($this->getPackages() as $package) {
            $links = $package->getRequires();

            // each loop needs its own "tree" as we want to show the complete dependent set of every needle
            // without warning all the time about finding circular deps
            $packagesInTree = $packagesFound;

            // Replacements are considered valid reasons for a package to be installed during forward resolution
            if (!$invert) {
                $links += $package->getReplaces();
            }

            // Require-dev is only relevant for the root package
            if ($package instanceof RootPackageInterface) {
                $links += $package->getDevRequires();
            }

            // Cross-reference all discovered links to the needles
            foreach ($links as $link) {
                foreach ($needles as $needle) {
                    if ($link->getTarget() === $needle) {
                        if (is_null($constraint) || (($link->getConstraint()->matches($constraint) === !$invert))) {
                            // already displayed this node's dependencies, cutting short
                            if (in_array($link->getSource(), $packagesInTree)) {
                                $results[$link->getSource()] = array($package, $link, false);
                                continue;
                            }
                            $packagesInTree[] = $link->getSource();
                            $dependents = $recurse ? $this->getDependents($link->getSource(), null, false, true, $packagesInTree) : array();
                            $results[$link->getSource()] = array($package, $link, $dependents);
                        }
                    }
                }
            }
        }

        ksort($results);

        return $results;
    }
}
