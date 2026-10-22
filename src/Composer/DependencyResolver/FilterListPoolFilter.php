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

namespace Composer\DependencyResolver;

use Composer\Filter\PackageFilter\FilterListRule;
use Composer\FilterList\FilterListAuditor;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Repository\RepositoryInterface;
use Composer\Util\HttpDownloader;

/**
 * @internal
 * @final
 * @readonly
 */
class FilterListPoolFilter
{
    /** @var PolicyConfig */
    private $policyConfig;
    /** @var FilterListAuditor */
    private $filterListAuditor;
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var ListPolicyConfig::BLOCK_SCOPE_* */
    private $blockScope;
    /** @var array<RepositoryInterface> */
    private $repositories;
    /** @var IOInterface */
    private $io;

    /**
     * @param ListPolicyConfig::BLOCK_SCOPE_* $blockScope
     * @param array<RepositoryInterface> $repositories Repositories consulted for filter list discovery
     */
    public function __construct(
        PolicyConfig $policyConfig,
        FilterListAuditor $filterListAuditor,
        HttpDownloader $httpDownloader,
        string $blockScope,
        array $repositories,
        IOInterface $io
    ) {
        $this->policyConfig = $policyConfig;
        $this->filterListAuditor = $filterListAuditor;
        $this->httpDownloader = $httpDownloader;
        $this->blockScope = $blockScope;
        $this->repositories = $repositories;
        $this->io = $io;
    }

    public function filter(Pool $pool, Request $request): Pool
    {
        // During UPDATE scope, packages that are locked are about to be installed
        // and must also be checked against install-scope filter lists (e.g. malware).
        $lockedNameVersionMap = $this->blockScope === ListPolicyConfig::BLOCK_SCOPE_UPDATE ? $this->buildLockedNameVersionMap($request) : [];
        $rule = new FilterListRule(
            $this->policyConfig,
            $this->filterListAuditor,
            $this->httpDownloader,
            $this->blockScope,
            $this->repositories,
            $this->io,
            function (BasePackage $package) use ($request, $lockedNameVersionMap): bool {
                return $this->isLockedEquivalent($package, $request, $lockedNameVersionMap);
            }
        );

        $rejections = $rule->getRejections($pool->getPackages());
        if (count($rejections) === 0) {
            return $pool;
        }

        $packages = [];
        $policyRemovedVersions = $pool->getAllPolicyRemovedVersions();
        foreach ($pool->getPackages() as $index => $package) {
            if (isset($rejections[$index])) {
                Pool::recordPolicyRemoval($policyRemovedVersions, $package, $rejections[$index]);
                continue;
            }

            $packages[] = $package;
        }

        return new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), $pool->getAllRemovedVersions(), $pool->getAllRemovedVersionsByPackage(), $policyRemovedVersions);
    }

    /**
     * Build a name => normalized-version-set lookup of the locked repository.
     *
     * Identity-based detection (`isLockedPackage`) only fires for packages the
     * solver explicitly locked via `Request::lockPackage()` — that covers
     * partial updates with an allow list, but NOT `update mirrors`, which
     * pins packages via `requireName(==version)` and loads fresh package
     * instances from configured repos. Matching by name + normalized version
     * recognises those pool packages as locked-equivalent too.
     *
     * @return array<string, array<string, true>>
     */
    private function buildLockedNameVersionMap(Request $request): array
    {
        $map = [];
        $lockedRepository = $request->getLockedRepository();
        if ($lockedRepository !== null) {
            foreach ($lockedRepository->getPackages() as $lockedPackage) {
                $map[$lockedPackage->getName()][$lockedPackage->getVersion()] = true;
            }
        }

        return $map;
    }

    /**
     * @param array<string, array<string, true>> $lockedNameVersionMap
     */
    private function isLockedEquivalent(BasePackage $package, Request $request, array $lockedNameVersionMap): bool
    {
        if ($request->isLockedPackage($package)) {
            return true;
        }

        return isset($lockedNameVersionMap[$package->getName()][$package->getVersion()]);
    }
}
