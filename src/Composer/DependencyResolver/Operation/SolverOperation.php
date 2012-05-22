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

use Composer\Package\Version\VersionParser;
use Composer\Package\PackageInterface;

/**
 * Abstract solver operation class.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
abstract class SolverOperation implements OperationInterface
{
    protected $reason;

    /**
     * Initializes operation.
     *
     * @param string $reason operation reason
     */
    public function __construct($reason = null)
    {
        $this->reason = $reason;
    }

    /**
     * Returns operation reason.
     *
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }

    protected function formatVersion(PackageInterface $package)
    {
        return VersionParser::formatVersion($package);
    }
}
