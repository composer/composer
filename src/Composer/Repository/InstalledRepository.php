<?php declare(strict_types=1);

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

use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Package\RootPackageInterface;
use Composer\Package\Link;

/**
 * Installed repository is a composite of all installed repo types.
 *
 * The main use case is tagging a repo as an "installed" repository, and offering a way to get providers/replacers easily.
 *
 * Installed repos are LockArrayRepository, InstalledRepositoryInterface, RootPackageRepository and PlatformRepository
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstalledRepository extends CompositeRepository
{
    /**
     * @param ConstraintInterface|string|null $constraint
     *
     * @return BasePackage[]
     */
    public function findPackagesWithReplacersAndProviders(string $name, $constraint = null): array
    {
        $name = strtolower($name);

        if (null !== $constraint && !$constraint instanceof ConstraintInterface) {
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($constraint);
        }

        $matches = [];
        foreach ($this->getRepositories() as $repo) {
            foreach ($repo->getPackages() as $candidate) {
                if ($name === $candidate->getName()) {
                    if (null === $constraint || $constraint->matches(new Constraint('==', $candidate->getVersion()))) {
                        $matches[] = $candidate;
                    }
                    continue;
                }

                foreach (array_merge($candidate->getProvides(), $candidate->getReplaces()) as $link) {
                    if (
                        $name === $link->getTarget()
                        && ($constraint === null || $constraint->matches($link->getConstraint()))
                    ) {
                        $matches[] = $candidate;
                        continue 2;
                    }
                }
            }
        }

        return $matches;
    }

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
     *
     * @return array[] An associative array of arrays as described above.
     * @phpstan-return array<array{0: PackageInterface, 1: Link, 2: array<mixed>|false}>
     */
    public function getDependents($needle, ?ConstraintInterface $constraint = null, bool $invert = false, bool $recurse = true, ?array $packagesFound = null): array
    {
        $needles = array_map('strtolower', (array) $needle);
        $results = [];

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

                // On forward search, check if any replaced package was required and add the replaced
                // packages to the list of needles. Contrary to the cross-reference link check below,
                // replaced packages are the target of links.
                foreach ($package->getReplaces() as $link) {
                    foreach ($needles as $needle) {
                        if ($link->getSource() === $needle) {
                            if ($constraint === null || ($link->getConstraint()->matches($constraint) === true)) {
                                // already displayed this node's dependencies, cutting short
                                if (in_array($link->getTarget(), $packagesInTree)) {
                                    $results[] = [$package, $link, false];
                                    continue;
                                }
                                $packagesInTree[] = $link->getTarget();
                                $dependents = $recurse ? $this->getDependents($link->getTarget(), null, false, true, $packagesInTree) : [];
                                $results[] = [$package, $link, $dependents];
                                $needles[] = $link->getTarget();
                            }
                        }
                    }
                }
                unset($needle);
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
                                $results[] = [$package, $link, false];
                                continue;
                            }
                            $packagesInTree[] = $link->getSource();
                            $dependents = $recurse ? $this->getDependents($link->getSource(), null, false, true, $packagesInTree) : [];
                            $results[] = [$package, $link, $dependents];
                        }
                    }
                }
            }

            // When inverting, we need to check for conflicts of the needles against installed packages
            if ($invert && in_array($package->getName(), $needles, true)) {
                foreach ($package->getConflicts() as $link) {
                    foreach ($this->findPackages($link->getTarget()) as $pkg) {
                        $version = new Constraint('=', $pkg->getVersion());
                        if ($link->getConstraint()->matches($version) === $invert) {
                            $results[] = [$package, $link, false];
                        }
                    }
                }
            }

            // List conflicts against X as they may explain why the current version was selected, or explain why it is rejected if the conflict matched when inverting
            foreach ($package->getConflicts() as $link) {
                if (in_array($link->getTarget(), $needles, true)) {
                    foreach ($this->findPackages($link->getTarget()) as $pkg) {
                        $version = new Constraint('=', $pkg->getVersion());
                        if ($link->getConstraint()->matches($version) === $invert) {
                            $results[] = [$package, $link, false];
                        }
                    }
                }
            }

            // When inverting, we need to check for conflicts of the needles' requirements against installed packages
            if ($invert && $constraint && in_array($package->getName(), $needles, true) && $constraint->matches(new Constraint('=', $package->getVersion()))) {
                foreach ($package->getRequires() as $link) {
                    if (PlatformRepository::isPlatformPackage($link->getTarget())) {
                        if ($this->findPackage($link->getTarget(), $link->getConstraint())) {
                            continue;
                        }

                        $platformPkg = $this->findPackage($link->getTarget(), '*');
                        $description = $platformPkg ? 'but '.$platformPkg->getPrettyVersion().' is installed' : 'but it is missing';
                        $results[] = [$package, new Link($package->getName(), $link->getTarget(), new MatchAllConstraint, Link::TYPE_REQUIRE, $link->getPrettyConstraint().' '.$description), false];

                        continue;
                    }

                    foreach ($this->getPackages() as $pkg) {
                        if (!in_array($link->getTarget(), $pkg->getNames())) {
                            continue;
                        }

                        $version = new Constraint('=', $pkg->getVersion());

                        if ($link->getTarget() !== $pkg->getName()) {
                            foreach (array_merge($pkg->getReplaces(), $pkg->getProvides()) as $prov) {
                                if ($link->getTarget() === $prov->getTarget()) {
                                    $version = $prov->getConstraint();
                                    break;
                                }
                            }
                        }

                        if (!$link->getConstraint()->matches($version)) {
                            // if we have a root package (we should but can not guarantee..) we show
                            // the root requires as well to perhaps allow to find an issue there
                            if ($rootPackage) {
                                foreach (array_merge($rootPackage->getRequires(), $rootPackage->getDevRequires()) as $rootReq) {
                                    if (in_array($rootReq->getTarget(), $pkg->getNames()) && !$rootReq->getConstraint()->matches($link->getConstraint())) {
                                        $results[] = [$package, $link, false];
                                        $results[] = [$rootPackage, $rootReq, false];
                                        continue 3;
                                    }
                                }

                                $results[] = [$package, $link, false];
                                $results[] = [$rootPackage, new Link($rootPackage->getName(), $link->getTarget(), new MatchAllConstraint, Link::TYPE_DOES_NOT_REQUIRE, 'but ' . $pkg->getPrettyVersion() . ' is installed'), false];
                            } else {
                                // no root so let's just print whatever we found
                                $results[] = [$package, $link, false];
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

    public function getRepoName(): string
    {
        return 'installed repo ('.implode(', ', array_map(static function ($repo): string {
            return $repo->getRepoName();
        }, $this->getRepositories())).')';
    }

    /**
     * @inheritDoc
     */
    public function addRepository(RepositoryInterface $repository): void
    {
        if (
            $repository instanceof LockArrayRepository
            || $repository instanceof InstalledRepositoryInterface
            || $repository instanceof RootPackageRepository
            || $repository instanceof PlatformRepository
        ) {
            parent::addRepository($repository);

            return;
        }

        throw new \LogicException('An InstalledRepository can not contain a repository of type '.get_class($repository).' ('.$repository->getRepoName().')');
    }
}
