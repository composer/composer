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

namespace Composer\Repository\Pear;

/**
 * PEAR package release info
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class ReleaseInfo
{
    private $stability;
    private $dependencyInfo;

    /**
     * @param string         $stability
     * @param DependencyInfo $dependencyInfo
     */
    public function __construct($stability, $dependencyInfo)
    {
        $this->stability = $stability;
        $this->dependencyInfo = $dependencyInfo;
    }

    /**
     * @return DependencyInfo release dependencies
     */
    public function getDependencyInfo()
    {
        return $this->dependencyInfo;
    }

    /**
     * @return string release stability
     */
    public function getStability()
    {
        return $this->stability;
    }
}
