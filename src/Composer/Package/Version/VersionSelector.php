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
use Composer\Package\PackageInterface;

/**
 * Selects the best possible version for a package
 *
 * @author Ryan Weaver <ryan@knpuniversity.com>
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
     * @param string    $packageName
     * @param string    $targetPackageVersion
     * @return PackageInterface|bool
     */
    public function findBestCandidate($packageName, $targetPackageVersion = null)
    {
        $constraint = $targetPackageVersion ? $this->getParser()->parseConstraints($targetPackageVersion) : null;
        $candidates = $this->pool->whatProvides($packageName, $constraint, true);

        if (!$candidates) {
            return false;
        }

        // select highest version if we have many
        // logic is repeated in InitCommand
        $package = reset($candidates);
        foreach ($candidates as $candidate) {
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
     *  * 1.2.1         -> ~1.2
     *  * 1.2           -> ~1.2
     *  * v3.2.1        -> ~3.2
     *  * 2.0-beta.1    -> ~2.0-beta.1
     *  * dev-master    -> dev-master    (dev versions are untouched)
     *
     * @param PackageInterface $package
     * @return string
     */
    public function findRecommendedRequireVersion(PackageInterface $package)
    {
        $version = $package->getPrettyVersion();
        if (!$package->isDev()) {
            // remove the v prefix if there is one
            if (substr($version, 0, 1) == 'v') {
                $version = substr($version, 1);
            }

            // for stable packages only, we try to transform 2.1.1 to 2.1
            // this allows you to upgrade through minor versions
            if ($package->getStability() == 'stable') {
                $semanticVersionParts = explode('.', $version);
                // check to see if we have a normal 1.2.6 semantic version
                if (count($semanticVersionParts) == 3) {
                    // remove the last part (i.e. the patch version number)
                    unset($semanticVersionParts[2]);
                    $version = implode('.', $semanticVersionParts);
                }
            }

            // 2.1 -> ~2.1
            $version = '~'.$version;
        }

        return $version;
    }

    private function getParser()
    {
        if ($this->parser === null) {
            $this->parser = new VersionParser();
        }

        return $this->parser;
    }
}
