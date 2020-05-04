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

namespace Composer\Package\Version;

use Composer\DependencyResolver\Pool;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Composer;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Semver\Constraint\Constraint;

/**
 * Selects the best possible version for a package
 *
 * @author Ryan Weaver <ryan@knpuniversity.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionSelector
{
    private $pool;

    private $parser;

    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Given a package name and optional version, returns the latest PackageInterface
     * that matches.
     *
     * @param  string                $packageName
     * @param  string                $targetPackageVersion
     * @param  string                $targetPhpVersion
     * @param  string                $preferredStability
     * @return PackageInterface|bool
     */
    public function findBestCandidate($packageName, $targetPackageVersion = null, $targetPhpVersion = null, $preferredStability = 'stable')
    {
        $constraint = $targetPackageVersion ? $this->getParser()->parseConstraints($targetPackageVersion) : null;
        $candidates = $this->pool->whatProvides(strtolower($packageName), $constraint, true);

        if ($targetPhpVersion) {
            $phpConstraint = new Constraint('==', $this->getParser()->normalize($targetPhpVersion));
            $composerRuntimeConstraint = new Constraint('==', $this->getParser()->normalize(Composer::RUNTIME_API_VERSION));
            $composerPluginConstraint = new Constraint('==', $this->getParser()->normalize(PluginInterface::PLUGIN_API_VERSION));
            $candidates = array_filter($candidates, function ($pkg) use ($phpConstraint, $composerPluginConstraint, $composerRuntimeConstraint) {
                $reqs = $pkg->getRequires();

                return (!isset($reqs['php']) || $reqs['php']->getConstraint()->matches($phpConstraint))
                    && (!isset($reqs['composer-plugin-api']) || $reqs['composer-plugin-api']->getConstraint()->matches($composerPluginConstraint))
                    && (!isset($reqs['composer-runtime-api']) || $reqs['composer-runtime-api']->getConstraint()->matches($composerRuntimeConstraint));
            });
        }

        if (!$candidates) {
            return false;
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

        return $package;
    }

    /**
     * Given a concrete version, this returns a ~ constraint (when possible)
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
    public function findRecommendedRequireVersion(PackageInterface $package)
    {
        $version = $package->getVersion();
        if (!$package->isDev()) {
            return $this->transformVersion($version, $package->getPrettyVersion(), $package->getStability());
        }

        $loader = new ArrayLoader($this->getParser());
        $dumper = new ArrayDumper();
        $extra = $loader->getBranchAlias($dumper->dump($package));
        if ($extra) {
            $extra = preg_replace('{^(\d+\.\d+\.\d+)(\.9999999)-dev$}', '$1.0', $extra, -1, $count);
            if ($count) {
                $extra = str_replace('.9999999', '.0', $extra);

                return $this->transformVersion($extra, $extra, 'dev');
            }
        }

        return $package->getPrettyVersion();
    }

    private function transformVersion($version, $prettyVersion, $stability)
    {
        // attempt to transform 2.1.1 to 2.1
        // this allows you to upgrade through minor versions
        $semanticVersionParts = explode('.', $version);

        // check to see if we have a semver-looking version
        if (count($semanticVersionParts) == 4 && preg_match('{^0\D?}', $semanticVersionParts[3])) {
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
        if ($stability != 'stable') {
            $version .= '@'.$stability;
        }

        // 2.1 -> ^2.1
        return '^' . $version;
    }

    private function getParser()
    {
        if ($this->parser === null) {
            $this->parser = new VersionParser();
        }

        return $this->parser;
    }
}
