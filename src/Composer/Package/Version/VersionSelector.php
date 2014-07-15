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

    private function getParser()
    {
        if ($this->parser === null) {
            $this->parser = new VersionParser();
        }

        return $this->parser;
    }
}
