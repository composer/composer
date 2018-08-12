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
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Link;

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
        $needles = array_map('strtolower', (array) $needle);
        $results = array();

        // initialize the array with the needles before any recursion occurs
        if (null === $packagesFound) {
            $packagesFound = $needles;
        }

        // locate root package for use below
        $rootPackage = null;
        foreach ($this->getPackages() as $package) {
            if ($package instanceof RootPackageInterface) {
                $rootPackage = $package;
                break;
            }
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
                        if ($constraint === null || ($link->getConstraint()->matches($constraint) === !$invert)) {
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

            // When inverting, we need to check for conflicts of the needles against installed packages
            if ($invert && in_array($package->getName(), $needles)) {
                foreach ($package->getConflicts() as $link) {
                    foreach ($this->findPackages($link->getTarget()) as $pkg) {
                        $version = new Constraint('=', $pkg->getVersion());
                        if ($link->getConstraint()->matches($version) === $invert) {
                            $results[] = array($package, $link, false);
                        }
                    }
                }
            }

            // When inverting, we need to check for conflicts of the needles' requirements against installed packages
            if ($invert && $constraint && in_array($package->getName(), $needles) && $constraint->matches(new Constraint('=', $package->getVersion()))) {
                foreach ($package->getRequires() as $link) {
                    if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $link->getTarget())) {
                        if ($this->findPackage($link->getTarget(), $link->getConstraint())) {
                            continue;
                        }

                        $platformPkg = $this->findPackage($link->getTarget(), '*');
                        $description = $platformPkg ? 'but '.$platformPkg->getPrettyVersion().' is installed' : 'but it is missing';
                        $results[] = array($package, new Link($package->getName(), $link->getTarget(), null, 'requires', $link->getPrettyConstraint().' '.$description), false);

                        continue;
                    }

                    foreach ($this->getPackages() as $pkg) {
                        if (!in_array($link->getTarget(), $pkg->getNames())) {
                            continue;
                        }

                        $version = new Constraint('=', $pkg->getVersion());
                        if (!$link->getConstraint()->matches($version)) {
                            // if we have a root package (we should but can not guarantee..) we show
                            // the root requires as well to perhaps allow to find an issue there
                            if ($rootPackage) {
                                foreach (array_merge($rootPackage->getRequires(), $rootPackage->getDevRequires()) as $rootReq) {
                                    if (in_array($rootReq->getTarget(), $pkg->getNames()) && !$rootReq->getConstraint()->matches($link->getConstraint())) {
                                        $results[] = array($package, $link, false);
                                        $results[] = array($rootPackage, $rootReq, false);
                                        continue 3;
                                    }
                                }
                                $results[] = array($package, $link, false);
                                $results[] = array($rootPackage, new Link($rootPackage->getName(), $link->getTarget(), null, 'does not require', 'but ' . $pkg->getPrettyVersion() . ' is installed'), false);
                            } else {
                                // no root so let's just print whatever we found
                                $results[] = array($package, $link, false);
                            }
                        }

                        continue 2;
                    }
                }
            }
        }

        ksort($results);

        return $results;
    }
}
