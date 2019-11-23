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

namespace Composer\DependencyResolver\Operation;

use Composer\Package\PackageInterface;

/**
 * Solver update operation.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class UpdateOperation extends SolverOperation
{
    protected $initialPackage;
    protected $targetPackage;

    /**
     * Initializes update operation.
     *
     * @param PackageInterface $initial initial package
     * @param PackageInterface $target  target package (updated)
     * @param string           $reason  update reason
     */
    public function __construct(PackageInterface $initial, PackageInterface $target, $reason = null)
    {
        parent::__construct($reason);

        $this->initialPackage = $initial;
        $this->targetPackage = $target;
    }

    /**
     * Returns initial package.
     *
     * @return PackageInterface
     */
    public function getInitialPackage()
    {
        return $this->initialPackage;
    }

    /**
     * Returns target package.
     *
     * @return PackageInterface
     */
    public function getTargetPackage()
    {
        return $this->targetPackage;
    }

    /**
     * Returns job type.
     *
     * @return string
     */
    public function getJobType()
    {
        return 'update';
    }

    /**
     * {@inheritDoc}
     */
    public function show($lock)
    {
        $fromVersion = $this->initialPackage->getFullPrettyVersion();
        $toVersion = $this->targetPackage->getFullPrettyVersion();

        if ($fromVersion === $toVersion && $this->initialPackage->getSourceReference() !== $this->targetPackage->getSourceReference()) {
            $fromVersion = $this->initialPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_SOURCE_REF);
            $toVersion = $this->targetPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_SOURCE_REF);
        } elseif ($fromVersion === $toVersion && $this->initialPackage->getDistReference() !== $this->targetPackage->getDistReference()) {
            $fromVersion = $this->initialPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_DIST_REF);
            $toVersion = $this->targetPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_DIST_REF);
        }

        return 'Updating '.$this->initialPackage->getPrettyName().' ('.$fromVersion.' => '.$toVersion.')';
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        return $this->show(false);
    }
}
