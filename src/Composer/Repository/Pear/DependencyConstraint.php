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
 * PEAR package release dependency info
 *
 * @author Alexey Prilipko <palex@farpost.com>
 */
class DependencyConstraint
{
    private $type;
    private $constraint;
    private $channelName;
    private $packageName;

    /**
     * @param string $type
     * @param string $constraint
     * @param string $channelName
     * @param string $packageName
     */
    public function __construct($type, $constraint, $channelName, $packageName)
    {
        $this->type = $type;
        $this->constraint = $constraint;
        $this->channelName = $channelName;
        $this->packageName = $packageName;
    }

    public function getChannelName()
    {
        return $this->channelName;
    }

    public function getConstraint()
    {
        return $this->constraint;
    }

    public function getPackageName()
    {
        return $this->packageName;
    }

    public function getType()
    {
        return $this->type;
    }
}
