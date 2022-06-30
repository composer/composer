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

namespace Composer\Package\Version;

use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Composer;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Pcre\Preg;
use Composer\Repository\RepositorySet;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Selects the best possible version for a package
 *
 * @author Ryan Weaver <ryan@knpuniversity.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionSelector
{
    /** @var RepositorySet */
    private $repositorySet;

    /** @var array<string, ConstraintInterface[]> */
    private $platformConstraints = array();

    /** @var VersionParser */
    private $parser;

    /**
     * @param PlatformRepository $platformRepo If passed in, the versions found will be filtered against their requirements to eliminate any not matching the current platform packages
     */
    public function __construct(RepositorySet $repositorySet, PlatformRepository $platformRepo = null)
    {
        $this->repositorySet = $repositorySet;
        if ($platformRepo) {
            foreach ($platformRepo->getPackages() as $package) {
                $this->platformConstraints[$package->getName()][] = new Constraint('==', $package->getVersion());
            }
        }
    }

    /**
     * Given a package name and optional version, returns the latest PackageInterface
     * that matches.
     *
     * @param string                                           $packageName
     * @param string                                           $targetPackageVersion
     * @param string                                           $preferredStability
     * @param PlatformRequirementFilterInterface|bool|string[] $platformRequirementFilter
     * @param int                                              $repoSetFlags
     * @param IOInterface|null                                 $io                        If passed, warnings will be output there in case versions cannot be selected due to platform requirements
     * @return PackageInterface|false
     */
    public function findBestCandidate(string $packageName, string $targetPackageVersion = null, string $preferredStability = 'stable', $platformRequirementFilter = null, int $repoSetFlags = 0, ?IOInterface $io = null)
    {
        if (!isset(BasePackage::$stabilities[$preferredStability])) {
            // If you get this, maybe you are still relying on the Composer 1.x signature where the 3rd arg was the php version
            throw new \UnexpectedValueException('Expected a valid stability name as 3rd argument, got '.$preferredStability);
        }

        if (null === $platformRequirementFilter) {
            $platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();
        } elseif (!($platformRequirementFilter instanceof PlatformRequirementFilterInterface)) {
            trigger_error('VersionSelector::findBestCandidate with ignored platform reqs as bool|array is deprecated since Composer 2.2, use an instance of PlatformRequirementFilterInterface instead.', E_USER_DEPRECATED);
            $platformRequirementFilter = PlatformRequirementFilterFactory::fromBoolOrList($platformRequirementFilter);
        }

        $constraint = $targetPackageVersion ? $this->getParser()->parseConstraints($targetPackageVersion) : null;
        $candidates = $this->repositorySet->findPackages(strtolower($packageName), $constraint, $repoSetFlags);
        $skippedWarnings = [];

        if ($this->platformConstraints && !($platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter)) {
            $platformConstraints = $this->platformConstraints;
            $candidates = array_filter($candidates, static function ($pkg) use ($platformConstraints, $platformRequirementFilter, &$skippedWarnings): bool {
                $reqs = $pkg->getRequires();

                foreach ($reqs as $name => $link) {
                    if (!$platformRequirementFilter->isIgnored($name)) {
                        if (isset($platformConstraints[$name])) {
                            foreach ($platformConstraints[$name] as $constraint) {
                                if ($link->getConstraint()->matches($constraint)) {
                                    continue 2;
                                }
                            }

                            $skippedWarnings[$pkg->getName()][] = ['version' => $pkg->getPrettyVersion(), 'link' => $link, 'reason' => 'is not satisfied by your platform'];
                            return false;
                        } elseif (PlatformRepository::isPlatformPackage($name)) {
                            // Package requires a platform package that is unknown on current platform.
                            // It means that current platform cannot validate this constraint and so package is not installable.
                            $skippedWarnings[$pkg->getName()][] = ['version' => $pkg->getPrettyVersion(), 'link' => $link, 'reason' => 'is missing from your platform'];
                            return false;
                        }
                    }
                }

                return true;
            });
        }

        if (!$candidates) {
            return false;
        }

        if (count($skippedWarnings) > 0 && $io !== null) {
            foreach ($skippedWarnings as $name => $warnings) {
                foreach ($warnings as $index => $warning) {
                    $link = $warning['link'];
                    $latest = $index === 0 ? "'s latest version" : '';
                    $io->writeError(
                        '<warning>Cannot use '.$name.$latest.' '.$warning['version'].' as it '.$link->getDescription().' '.$link->getTarget().' '.$link->getPrettyConstraint().' which '.$warning['reason'].'.</>',
                        true,
                        $index === 0 ? IOInterface::NORMAL : IOInterface::VERBOSE
                    );
                }
            }
        }

        // select highest version if we have many
        $package = reset($candidates);
        $minPriority = BasePackage::$stabilities[$preferredStability];
        foreach ($candidates as $candidate) {
            $candidatePriority = $candidate->getStabilityPriority();
            $currentPriority = $package->getStabilityPriority();

            // candidate is less stable than our preferred stability,
            // and current package is more stable than candidate, skip it
            if ($minPriority < $candidatePriority && $currentPriority < $candidatePriority) {
                continue;
            }

            // candidate is less stable than our preferred stability,
            // and current package is less stable than candidate, select candidate
            if ($minPriority < $candidatePriority && $candidatePriority < $currentPriority) {
                $package = $candidate;
                continue;
            }

            // candidate is more stable than our preferred stability,
            // and current package is less stable than preferred stability, select candidate
            if ($minPriority >= $candidatePriority && $minPriority < $currentPriority) {
                $package = $candidate;
                continue;
            }

            // select highest version of the two
            if (version_compare($package->getVersion(), $candidate->getVersion(), '<')) {
                $package = $candidate;
            }
        }

        // if we end up with 9999999-dev as selected package, make sure we use the original version instead of the alias
        if ($package instanceof AliasPackage && $package->getVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
            $package = $package->getAliasOf();
        }

        return $package;
    }

    /**
     * Given a concrete version, this returns a ^ constraint (when possible)
     * that should be used, for example, in composer.json.
     *
     * For example:
     *  * 1.2.1         -> ^1.2
     *  * 1.2           -> ^1.2
     *  * v3.2.1        -> ^3.2
     *  * 2.0-beta.1    -> ^2.0@beta
     *  * dev-master    -> ^2.1@dev      (dev version with alias)
     *  * dev-master    -> dev-master    (dev versions are untouched)
     *
     * @param  PackageInterface $package
     * @return string
     */
    public function findRecommendedRequireVersion(PackageInterface $package): string
    {
        // Extensions which are versioned in sync with PHP should rather be required as "*" to simplify
        // the requires and have only one required version to change when bumping the php requirement
        if (0 === strpos($package->getName(), 'ext-')) {
            $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
            $extVersion = implode('.', array_slice(explode('.', $package->getVersion()), 0, 3));
            if ($phpVersion === $extVersion) {
                return '*';
            }
        }

        $version = $package->getVersion();
        if (!$package->isDev()) {
            return $this->transformVersion($version, $package->getPrettyVersion(), $package->getStability());
        }

        $loader = new ArrayLoader($this->getParser());
        $dumper = new ArrayDumper();
        $extra = $loader->getBranchAlias($dumper->dump($package));
        if ($extra && $extra !== VersionParser::DEFAULT_BRANCH_ALIAS) {
            $extra = Preg::replace('{^(\d+\.\d+\.\d+)(\.9999999)-dev$}', '$1.0', $extra, -1, $count);
            if ($count) {
                $extra = str_replace('.9999999', '.0', $extra);

                return $this->transformVersion($extra, $extra, 'dev');
            }
        }

        return $package->getPrettyVersion();
    }

    /**
     * @param string $version
     * @param string $prettyVersion
     * @param string $stability
     *
     * @return string
     */
    private function transformVersion(string $version, string $prettyVersion, string $stability): string
    {
        // attempt to transform 2.1.1 to 2.1
        // this allows you to upgrade through minor versions
        $semanticVersionParts = explode('.', $version);

        // check to see if we have a semver-looking version
        if (count($semanticVersionParts) === 4 && Preg::isMatch('{^0\D?}', $semanticVersionParts[3])) {
            // remove the last parts (i.e. the patch version number and any extra)
            if ($semanticVersionParts[0] === '0') {
                unset($semanticVersionParts[3]);
            } else {
                unset($semanticVersionParts[2], $semanticVersionParts[3]);
            }
            $version = implode('.', $semanticVersionParts);
        } else {
            return $prettyVersion;
        }

        // append stability flag if not default
        if ($stability !== 'stable') {
            $version .= '@'.$stability;
        }

        // 2.1 -> ^2.1
        return '^' . $version;
    }

    /**
     * @return VersionParser
     */
    private function getParser(): VersionParser
    {
        if ($this->parser === null) {
            $this->parser = new VersionParser();
        }

        return $this->parser;
    }
}
