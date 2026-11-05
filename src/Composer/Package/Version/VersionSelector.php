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

use Composer\Filter\PackageFilter\CachedRule;
use Composer\Filter\PackageFilter\PackageFilter;
use Composer\Filter\PackageFilter\PackageFilterResult;
use Composer\Filter\PackageFilter\PlatformRule;
use Composer\Filter\PackageFilter\RejectionReason;
use Composer\Filter\PackageFilter\RejectionRuleInterface;
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
use Composer\Policy\CooldownPolicyConfig;
use Composer\Filter\PackageFilter\CooldownRule;
use Composer\Repository\RepositorySet;
use Composer\Repository\PlatformRepository;
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

    /** @var ?PlatformRepository */
    private $platformRepo;

    /** @var VersionParser */
    private $parser;

    /** @var ?CooldownPolicyConfig */
    private $cooldown;

    /** @var list<RejectionRuleInterface> */
    private $policyRules = [];

    /** @var PackageFilterResult */
    private $lastResult;

    /** @var ?array{name: string, constraint: ?ConstraintInterface, minPriority: BasePackage::STABILITY_*, platformRequirementFilter: PlatformRequirementFilterInterface} */
    private $lastLookup = null;

    /**
     * @param PlatformRepository   $platformRepo If passed in, the versions found will be filtered against their requirements to eliminate any not matching the current platform packages
     * @param CooldownPolicyConfig $cooldown     If passed in, versions still within the configured cooldown are skipped
     * @param list<RejectionRuleInterface> $policyRules @internal the other dependency policies, versions they reject are skipped too
     */
    public function __construct(RepositorySet $repositorySet, ?PlatformRepository $platformRepo = null, ?CooldownPolicyConfig $cooldown = null, array $policyRules = [])
    {
        $this->repositorySet = $repositorySet;
        $this->platformRepo = $platformRepo;
        $this->cooldown = $cooldown;
        foreach ($policyRules as $rule) {
            $this->policyRules[] = new CachedRule($rule);
        }
        $this->lastResult = new PackageFilterResult([]);
    }

    /**
     * Given a package name and optional version, returns the latest PackageInterface
     * that matches.
     *
     * @param PlatformRequirementFilterInterface|bool|string[] $platformRequirementFilter
     * @param IOInterface|null                                 $io                        If passed, warnings will be output there in case versions cannot be selected due to platform requirements
     * @param callable(PackageInterface):bool|bool             $showWarnings
     * @return PackageInterface|false
     */
    public function findBestCandidate(string $packageName, ?string $targetPackageVersion = null, string $preferredStability = 'stable', $platformRequirementFilter = null, int $repoSetFlags = 0, ?IOInterface $io = null, $showWarnings = true)
    {
        if (!isset(BasePackage::STABILITIES[$preferredStability])) {
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
        $this->lastLookup = ['name' => strtolower($packageName), 'constraint' => $constraint, 'minPriority' => BasePackage::STABILITIES[$preferredStability], 'platformRequirementFilter' => $platformRequirementFilter];
        $candidates = $this->repositorySet->findPackages($this->lastLookup['name'], $constraint, $repoSetFlags);
        usort($candidates, self::getPreferenceComparator($this->lastLookup['minPriority']));

        // every rule checks every candidate, so callers can tell what alone keeps a version out
        $this->lastResult = $this->createFilter($platformRequirementFilter)->filter($candidates, true);

        if ($io !== null) {
            $this->warnAboutRejections($io, $showWarnings);
        }

        $package = $this->lastResult->getAccepted()[0] ?? null;
        if ($package === null) {
            return false;
        }

        // if we end up with 9999999-dev as selected package, make sure we use the original version instead of the alias
        if ($package instanceof AliasPackage && $package->getVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
            $package = $package->getAliasOf();
        }

        return $package;
    }

    /**
     * The candidates of the last findBestCandidate() call in order of preference, with every reason that rejects each of them
     *
     * @internal
     */
    public function getLastResult(): PackageFilterResult
    {
        return $this->lastResult;
    }

    /**
     * Looks the package of the last findBestCandidate() call up again in every repository and stability, to explain why no version was selected
     *
     * @internal
     */
    public function explainLastLookup(): PackageFilterResult
    {
        if ($this->lastLookup === null) {
            throw new \LogicException('findBestCandidate() must be called before explainLastLookup()');
        }

        $result = $this->repositorySet->findPackagesWithRejections($this->lastLookup['name'], $this->lastLookup['constraint'])
            ->sorted(self::getPreferenceComparator($this->lastLookup['minPriority']));

        return $this->createFilter($this->lastLookup['platformRequirementFilter'])->apply($result, true);
    }

    /**
     * Applies the policy rules to all versions of the given packages at once, so the data they need is fetched in bulk
     * before the packages are looked up one by one
     *
     * @internal
     * @param list<string> $packageNames
     */
    public function preloadPolicyData(array $packageNames): void
    {
        if (\count($this->policyRules) === 0) {
            return;
        }

        $packages = [];
        foreach ($packageNames as $packageName) {
            foreach ($this->repositorySet->findPackages(strtolower($packageName)) as $package) {
                $packages[] = $package;
            }
        }

        (new PackageFilter($this->policyRules))->filter($packages, true);
    }

    private function createFilter(PlatformRequirementFilterInterface $platformRequirementFilter): PackageFilter
    {
        $rules = $this->policyRules;
        if ($this->cooldown !== null) {
            $rules[] = new CooldownRule($this->cooldown);
        }
        if ($this->platformRepo !== null) {
            $rules[] = new PlatformRule($this->platformRepo, $platformRequirementFilter);
        }

        return new PackageFilter($rules);
    }

    /**
     * @param BasePackage::STABILITY_* $minPriority the priority of the preferred stability
     * @return callable(BasePackage, BasePackage): int
     */
    private static function getPreferenceComparator(int $minPriority): callable
    {
        return static function (BasePackage $a, BasePackage $b) use ($minPriority): int {
            $aPriority = $a->getStabilityPriority();
            $bPriority = $b->getStabilityPriority();

            // A is less stable than our preferred stability,
            // and B is more stable than A, select B
            if ($minPriority < $aPriority && $bPriority < $aPriority) {
                return 1;
            }

            // A is less stable than our preferred stability,
            // and B is less stable than A, select A
            if ($minPriority < $aPriority && $aPriority < $bPriority) {
                return -1;
            }

            // A is more stable than our preferred stability,
            // and B is less stable than preferred stability, select A
            if ($minPriority >= $aPriority && $minPriority < $bPriority) {
                return -1;
            }

            // select highest version of the two
            return version_compare($b->getVersion(), $a->getVersion());
        };
    }

    /**
     * Explains why candidates preferred over the selected one were skipped, those dependency policies reject first
     *
     * @param callable(PackageInterface):bool|bool $showWarnings
     */
    private function warnAboutRejections(IOInterface $io, $showWarnings): void
    {
        $candidates = $this->lastResult->getPackages();

        /** @var array<string, true> $alreadySeenNames */
        $alreadySeenNames = [];
        /** @var array<string, true> $acceptedNames */
        $acceptedNames = [];
        foreach ($candidates as $pkg) {
            $reasons = $this->getPolicyReasons($pkg);
            if (\count($reasons) === 0) {
                $acceptedNames[$pkg->getName()] = true;
                continue;
            }

            // ranked below a candidate the policies let through (e.g. an LTS backport), so rejecting it does not affect the pick
            if (isset($acceptedNames[$pkg->getName()])) {
                continue;
            }

            $isLatestVersion = !isset($alreadySeenNames[$pkg->getName()]);
            $alreadySeenNames[$pkg->getName()] = true;
            if (self::shouldWarn($pkg, $showWarnings)) {
                $io->writeError(
                    '<warning>Cannot use '.$pkg->getPrettyName().($isLatestVersion ? "'s latest version" : '').' '.$pkg->getPrettyVersion().' as it '.implode(' and ', array_map(static function (RejectionReason $reason): string {
                        return $reason->getVerb(false).' '.$reason->getDescription();
                    }, $reasons)).'.</>',
                    true,
                    $isLatestVersion ? IOInterface::NORMAL : IOInterface::VERBOSE
                );
            }
        }

        /** @var array<string, true> $alreadyWarnedNames */
        $alreadyWarnedNames = [];
        $alreadySeenNames = [];
        foreach ($candidates as $pkg) {
            $reasons = $this->lastResult->getReasons($pkg);
            if (\count($reasons) === 0) {
                // the selected candidate
                break;
            }

            $reason = $this->lastResult->getReason($pkg, RejectionReason::PLATFORM);
            if ($reason === null || \count($this->getPolicyReasons($pkg)) > 0) {
                continue;
            }

            foreach ($reason->getPlatformRequirements() as $requirement) {
                $link = $requirement['link'];
                $isLatestVersion = !isset($alreadySeenNames[$pkg->getName()]);
                $alreadySeenNames[$pkg->getName()] = true;
                if (self::shouldWarn($pkg, $showWarnings)) {
                    $isFirstWarning = !isset($alreadyWarnedNames[$pkg->getName().'/'.$link->getTarget()]);
                    $alreadyWarnedNames[$pkg->getName().'/'.$link->getTarget()] = true;
                    $latest = $isLatestVersion ? "'s latest version" : '';
                    $io->writeError(
                        '<warning>Cannot use '.$pkg->getPrettyName().$latest.' '.$pkg->getPrettyVersion().' as it '.$link->getDescription().' '.$link->getTarget().' '.$link->getPrettyConstraint().' which '.$requirement['problem'].'.</>',
                        true,
                        $isFirstWarning ? IOInterface::NORMAL : IOInterface::VERBOSE
                    );
                }
            }
        }
    }

    /**
     * @return list<RejectionReason>
     */
    private function getPolicyReasons(BasePackage $package): array
    {
        return array_values(array_filter($this->lastResult->getReasons($package), static function (RejectionReason $reason): bool {
            return $reason->isPolicy();
        }));
    }

    /**
     * @param callable(PackageInterface):bool|bool $showWarnings
     */
    private static function shouldWarn(PackageInterface $package, $showWarnings): bool
    {
        return $showWarnings === true || (is_callable($showWarnings) && $showWarnings($package));
    }

    /**
     * Given a concrete version, this returns a ^ constraint (when possible)
     * that should be used, for example, in composer.json.
     *
     * For example:
     *  * 1.2.1         -> ^1.2
     *  * 1.2.1.2       -> ^1.2
     *  * 1.2           -> ^1.2
     *  * v3.2.1        -> ^3.2
     *  * 2.0-beta.1    -> ^2.0@beta
     *  * dev-master    -> ^2.1@dev      (dev version with alias)
     *  * dev-master    -> dev-master    (dev versions are untouched)
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
            if ($count > 0) {
                $extra = str_replace('.9999999', '.0', $extra);

                return $this->transformVersion($extra, $extra, 'dev');
            }
        }

        return $package->getPrettyVersion();
    }

    private function transformVersion(string $version, string $prettyVersion, string $stability): string
    {
        // attempt to transform 2.1.1 to 2.1
        // this allows you to upgrade through minor versions
        $semanticVersionParts = explode('.', $version);

        // check to see if we have a semver-looking version
        if (count($semanticVersionParts) === 4 && Preg::isMatch('{^\d+\D?}', $semanticVersionParts[3])) {
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

    private function getParser(): VersionParser
    {
        if ($this->parser === null) {
            $this->parser = new VersionParser();
        }

        return $this->parser;
    }
}
